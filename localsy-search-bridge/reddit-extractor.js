// Localsy Reddit page extractor — content script for reddit.com / old.reddit.com.
// Ported from src/App/Adapters/Reddit.php. Same entity-discovery logic (t3_/t1_ IDs,
// weighted scoring, rtjson-content body extraction, depth=0+1 comment tree).
// Returns the plan's entity-based ExtractionResult schema.
//
// SSRF defense: see generic-extractor.js header for the four-layer model
// and the documented DNS-rebinding gap.
(async () => {
  // Set immediately — before any async work — so generic extractor skips this page.
  window.__localsy_extracted = true;

  // Authorization probe (same pattern as google-serp.js)
  const probe = await chrome.runtime.sendMessage({ type: "localsy_fetch_probe" }).catch(() => null);
  if (!probe?.allowed) return;

  // Redirect safety — reject if the browser landed on a private IP
  if (isPrivateHost(location.hostname)) {
    await chrome.runtime.sendMessage({
      type: "localsy_fetch_result",
      status: "rejected_redirect",
      content: { _redirect_target: location.href }
    }).catch(() => null);
    return;
  }

  // ── Mark so generic extractor skips this page ──
  window.__localsy_extracted = true;

  const pageUrl = location.href;
  const fetchedAt = new Date().toISOString();
  const isPost = isPostUrl(pageUrl);
  const debug = [];  // diagnostic log — sent back with result

  // Try Reddit JSON API first — works in background tabs, bypasses challenges.
  let jsonEntities = null;
  if (isPost) {
    try {
      jsonEntities = await extractFromRedditJSON(pageUrl);
    } catch (e) {
      debug.push({ step: "json_api_error", message: e.message });
    }
  }

  if (jsonEntities) {
    // JSON API succeeded — skip DOM extraction entirely.
    await chrome.runtime.sendMessage({
      type: "localsy_fetch_result",
      status: "success",
      content: {
        url: pageUrl,
        title: document.title,
        fetched_at: fetchedAt,
        entities: jsonEntities,
        _debug: debug
      }
    }).catch(() => null);
    return;
  }

  // JSON API failed — check if Reddit is showing a challenge page.
  const challenge = detectChallengeWithReason();
  if (challenge) {
    await chrome.runtime.sendMessage({
      type: "localsy_fetch_result",
      status: challenge.status,
      content: { _challenge_reason: challenge.reason, _debug: debug }
    }).catch(() => null);
    return;
  }

  debug.push({ step: "json_api_fallback", reason: "JSON API returned no entities, trying DOM" });

  // Diagnostic: snapshot of what's on the page before extraction
  debug.push({
    step: "page_snapshot",
    body_text_len: (document.body?.innerText || "").length,
    body_html_len: (document.body?.innerHTML || "").length,
    element_count: document.querySelectorAll("*").length,
    shred_post_count: document.querySelectorAll("shreddit-post").length,
    shred_comment_count: document.querySelectorAll("shreddit-comment").length,
    has_main: !!document.querySelector("main"),
    body_classes: (document.body?.className || "").slice(0, 200),
  });

  let entities;

  // Poll for content to appear — Reddit renders after document_idle
  const deadline = Date.now() + 10_000;
  while (Date.now() < deadline) {
    if (isPost) {
      entities = extractPostPage(pageUrl);
    } else {
      entities = extractSubredditPage(pageUrl);
    }

    // Check if we got real content
    let totalBody = 0;
    for (const e of entities) {
      totalBody += (e.body || "").length;
    }
    if (totalBody > 100) break;

    // Clear debug entries from this attempt to avoid clutter
    debug.length = debug.findIndex(d => d.step === "page_snapshot") + 1;
    debug.push({ step: "poll_retry", total_body_len: totalBody, remaining_ms: deadline - Date.now() });
    await new Promise(r => setTimeout(r, 500));
  }

  await chrome.runtime.sendMessage({
    type: "localsy_fetch_result",
    status: "success",
    content: {
      url: pageUrl,
      title: document.title,
      fetched_at: fetchedAt,
      entities,
      _debug: debug
    }
  }).catch(() => null);

  // ════════════════════════════════════════════════════════════════
  // Challenge detection (shared logic — also in generic-extractor.js)
  // ════════════════════════════════════════════════════════════════
  function detectChallenge() {
    if (/prove your humanity/i.test(document.title)) return "challenge_required";
    if (document.querySelector("iframe[src*='recaptcha']")) return "challenge_required";
    if (location.hostname === "consent.google.com") return "consent_required";
    if (location.pathname.startsWith("/sorry/")) return "challenge_required";
    return null;
  }

  /** Returns {status, reason} so we can diagnose false positives. */
  function detectChallengeWithReason() {
    if (/prove your humanity/i.test(document.title))
      return { status: "challenge_required", reason: "title: " + document.title.slice(0, 80) };
    if (document.querySelector("iframe[src*='recaptcha']"))
      return { status: "challenge_required", reason: "recaptcha iframe found" };
    if (location.hostname === "consent.google.com")
      return { status: "consent_required", reason: "hostname: consent.google.com" };
    if (location.pathname.startsWith("/sorry/"))
      return { status: "challenge_required", reason: "path: " + location.pathname };
    return null;
  }

  // ════════════════════════════════════════════════════════════════
  // Reddit JSON API extraction (works in background tabs, no DOM needed)
  // ════════════════════════════════════════════════════════════════

  async function extractFromRedditJSON(url) {
    // Build canonical .json URL from post ID (ignore title slug which may be
    // truncated by Reddit redirects).
    const pathParts = new URL(url).pathname.split('/').filter(Boolean);
    // pathParts = ["r", "subreddit", "comments", "postId", ...optionalSlug]
    if (pathParts.length < 4 || pathParts[0] !== 'r' || pathParts[2] !== 'comments') {
      debug.push({ step: "json_api_bad_url", path: new URL(url).pathname });
      return null;
    }
    const jsonUrl = `https://www.reddit.com/r/${pathParts[1]}/comments/${pathParts[3]}/.json`;
    debug.push({ step: "json_api_fetch", json_url: jsonUrl });

    const resp = await fetch(jsonUrl, { credentials: "same-origin" });
    if (!resp.ok) {
      debug.push({ step: "json_api_fetch_failed", status: resp.status });
      return null;
    }

    const data = await resp.json();
    if (!Array.isArray(data) || data.length < 2) {
      debug.push({ step: "json_api_bad_shape", is_array: Array.isArray(data), len: data?.length });
      return null;
    }

    const [postListing, commentListing] = data;
    const entities = [];

    // Parse the post (first child in post listing)
    const postChildren = postListing?.data?.children;
    if (!postChildren || postChildren.length === 0) {
      debug.push({ step: "json_api_no_post" });
      return null;
    }

    const postData = postChildren[0]?.data;
    if (!postData) return null;

    const postId = "t3_" + (postData.id || "");
    const postPermalink = postData.permalink || "";

    entities.push({
      entity_type: "post",
      entity_id: postId,
      canonical_url: absoluteRedditUrl(postPermalink),
      parent_id: null,
      author: postData.author ? "u/" + postData.author : "",
      score: postData.score ?? null,
      published: postData.created_utc ? new Date(postData.created_utc * 1000).toISOString() : null,
      body: normalizeText(postData.selftext || postData.body || ""),
      sections: [],
    });

    debug.push({
      step: "json_api_post",
      id: postId,
      title: (postData.title || "").slice(0, 80),
      author: postData.author,
      score: postData.score,
      body_len: (postData.selftext || "").length,
      comment_count: postData.num_comments,
    });

    // Parse comments (depth=0 top-level, depth=1 replies, >=2 ignored)
    const commentChildren = commentListing?.data?.children || [];
    let topCount = 0;
    const maxTop = 5;

    function walkComments(children, depth) {
      for (const child of children) {
        if (child.kind !== "t1") continue;
        const c = child.data;
        if (!c) continue;

        if (depth === 0) {
          if (topCount >= maxTop) break;
          topCount++;
        } else if (depth > 1) {
          continue; // depth >= 2 ignored
        }

        const commentId = "t1_" + (c.id || "");
        const body = normalizeText(c.body || "");

        if (!body) continue;

        entities.push({
          entity_type: depth === 0 ? "comment" : "reply",
          entity_id: commentId,
          canonical_url: absoluteRedditUrl(c.permalink || ""),
          parent_id: depth === 1 ? postId : null,
          depth,
          author: c.author ? "u/" + c.author : "",
          score: c.score ?? null,
          published: c.created_utc ? new Date(c.created_utc * 1000).toISOString() : null,
          body,
          sections: [],
        });

        // Recurse into replies (depth=1 only)
        if (depth === 0 && c.replies && c.replies.data && c.replies.data.children) {
          walkComments(c.replies.data.children, depth + 1);
        }
      }
    }

    walkComments(commentChildren, 0);

    debug.push({
      step: "json_api_done",
      total_entities: entities.length,
      comment_count: entities.length - 1,
    });

    return entities.length > 0 ? entities : null;
  }

  // ════════════════════════════════════════════════════════════════
  // DOM Extraction (fallback for old.reddit.com or when JSON API fails)
  // ════════════════════════════════════════════════════════════════

  function extractPostPage(url) {
    const postNode = findMainPost(url);

    if (postNode) {
      const postEntity = extractPostEntity(postNode);
      const commentEntities = extractCommentTree(5);
      return [postEntity, ...commentEntities];
    }

    // findMainPost failed — try page-level extraction for modern Reddit
    debug.push({ step: "extractPostPage_fallback", reason: "findMainPost returned null" });
    return extractFromPageDOM(url);
  }

  // ── Page-level fallback for www.reddit.com (no t3_ IDs, no semantic attrs) ──

  function extractFromPageDOM(url) {
    const entities = [];
    const thingId = postThingIdFromUrl(url) || "";

    // Try shreddit-post custom element (light DOM attributes only)
    const shredPost = document.querySelector("shreddit-post");
    debug.push({
      step: "extractFromPageDOM_shreddit",
      found: !!shredPost,
      attrs: shredPost ? [...shredPost.attributes].map(a => a.name).join(", ") : "none",
    });

    if (shredPost) {
      const id = shredPost.getAttribute("id") || thingId;
      const title = (shredPost.getAttribute("post-title") || "").trim()
        || extractPageTitleDOM();
      const author = shredPost.getAttribute("author") || extractAuthorDOM(shredPost);
      const score = nullableInt(shredPost.getAttribute("score"));
      const published = shredPost.getAttribute("created-timestamp") || null;
      const permalink = shredPost.getAttribute("permalink") || "";

      let body = "";
      // Try slotted content or light DOM children
      if (shredPost.shadowRoot) {
        const content = shredPost.shadowRoot.querySelector("[slot='post-content'], [data-testid='post-content'], .md");
        if (content) body = normalizeText(content.textContent);
      }
      if (!body) {
        // Walk direct text children of shreddit-post in light DOM
        body = normalizeText(shredPost.textContent);
        // Strip the title if it appears as text
        if (title && body.startsWith(title)) {
          body = body.slice(title.length).trim();
        }
      }

      entities.push({
        entity_type: "post",
        entity_id: id,
        canonical_url: absoluteRedditUrl(permalink) || url,
        parent_id: null,
        author,
        score,
        published,
        body,
        sections: [],
      });

      debug.push({
        step: "extractFromPageDOM_post",
        id, title_len: title.length, author, score, body_len: body.length,
      });
    } else {
      // No shreddit-post found — extract from visible page content.
      const title = extractPageTitleDOM();
      const body = extractPageTextDOM();

      entities.push({
        entity_type: "post",
        entity_id: thingId,
        canonical_url: url,
        parent_id: null,
        author: "",
        score: null,
        published: null,
        body,
        sections: body ? [{ heading: title, heading_level: 1, body }] : [],
      });

      debug.push({
        step: "extractFromPageDOM_generic",
        title_len: title.length, body_len: body.length,
      });
    }

    // Try shreddit-comment elements for comments
    const shredComments = document.querySelectorAll("shreddit-comment");
    debug.push({ step: "extractFromPageDOM_comments", shreddit_comment_count: shredComments.length });

    let topCount = 0;
    const maxTop = 5;
    for (const sc of shredComments) {
      if (topCount >= maxTop) break;

      const depth = parseInt(sc.getAttribute("depth") || "0", 10);
      if (isNaN(depth)) continue;

      const author = sc.getAttribute("author") || extractAuthorDOM(sc) || "";
      const commentScore = nullableInt(sc.getAttribute("score"));
      const commentPermalink = sc.getAttribute("permalink") || "";
      const commentId = sc.getAttribute("id") || sc.getAttribute("thingid") || "";

      let commentBody = "";
      if (sc.shadowRoot) {
        const c = sc.shadowRoot.querySelector("[slot='comment-body'], [data-testid='comment-body'], .md");
        if (c) commentBody = normalizeText(c.textContent);
      }
      if (!commentBody) {
        commentBody = normalizeText(sc.textContent);
      }

      if (!commentBody) continue;

      entities.push({
        entity_type: depth === 0 ? "comment" : "reply",
        entity_id: commentId || `c_${topCount}`,
        canonical_url: absoluteRedditUrl(commentPermalink) || "",
        parent_id: depth === 1 ? entities[0]?.entity_id || null : null,
        depth,
        author,
        score: commentScore,
        published: null,
        body: commentBody,
        sections: [],
      });

      if (depth === 0) topCount++;
    }

    return entities;
  }

  function extractPageTitleDOM() {
    const h1 = document.querySelector("h1");
    if (h1) {
      const text = (h1.textContent || "").trim();
      if (text && text.length > 3) return text;
    }
    return document.title.replace(/^(Reddit - | : Reddit$)/g, "").trim();
  }

  function extractPageTextDOM() {
    // Find the main content area — post body is typically between title and comments.
    // Strategy: find the shreddit-post or main element, get its text minus the title.
    const containers = [
      document.querySelector("shreddit-post"),
      document.querySelector("main"),
      document.querySelector("[role='main']"),
    ].filter(Boolean);

    for (const container of containers) {
      // Remove comment sections
      const clone = container.cloneNode(true);
      for (const c of clone.querySelectorAll("shreddit-comment, [data-testid='comment']")) {
        c.remove();
      }
      const text = normalizeText(clone.textContent);
      if (text.length > 100) return text;
    }

    // Last resort: page body text minus obvious nav/header
    const bodyClone = document.body.cloneNode(true);
    for (const el of bodyClone.querySelectorAll("header, nav, footer, script, style, noscript, [role='navigation']")) {
      el.remove();
    }
    return normalizeText(bodyClone.textContent);
  }

  function extractSubredditPage(url) {
    const posts = [];
    const seen = new Set();
    const maxPosts = 25;

    for (const node of findPostEntities()) {
      if (posts.length >= maxPosts) break;
      if (!looksLikePostEntity(node) || isPromotedPost(node)) continue;

      const parsed = extractPostEntity(node);
      const key = parsed.entity_id || parsed.canonical_url;
      if (!key || seen.has(key)) continue;

      seen.add(key);
      posts.push(parsed);
    }

    return posts;
  }

  // ── Entity discovery ──

  function findPostEntities() {
    return xpathElements('//*[starts-with(@id, "t3_") or starts-with(@thingid, "t3_") or (@post-title and @permalink)]');
  }

  function findCommentEntities() {
    return xpathElements(
      '//*[starts-with(@id, "t1_") or starts-with(@thingid, "t1_") or (@depth and @author and @permalink) or starts-with(@aria-label, "Comment from ")]'
    );
  }

  function findMainPost(url) {
    const thingId = postThingIdFromUrl(url);

    debug.push({ step: "findMainPost_start", thingId, url_path: new URL(url).pathname });

    if (thingId) {
      const selector = `[id="${CSS.escape(thingId)}"], [thingid="${CSS.escape(thingId)}"]`;
      const el = document.querySelector(selector);
      debug.push({ step: "findMainPost_direct_query", selector, found: !!el, tag: el?.tagName || null });
      if (el && looksLikePostEntity(el)) {
        debug.push({ step: "findMainPost_matched_direct", tag: el.tagName });
        return el;
      }
    }

    const targetPath = normalizeRedditPath(new URL(url).pathname);
    const allPostEntities = findPostEntities();
    debug.push({ step: "findMainPost_xpath_entities", count: allPostEntities.length });

    for (const node of allPostEntities) {
      if (!looksLikePostEntity(node)) continue;
      const perm = normalizeRedditPath(node.getAttribute("permalink") || "");
      if (perm && perm === targetPath) {
        debug.push({ step: "findMainPost_matched_permalink", tag: node.tagName, perm });
        return node;
      }
    }

    for (const node of allPostEntities) {
      if (looksLikePostEntity(node)) {
        debug.push({ step: "findMainPost_matched_first", tag: node.tagName });
        return node;
      }
    }

    debug.push({ step: "findMainPost_FAILED", xpath_entity_count: allPostEntities.length });
    return null;
  }

  // ── Entity scoring ──

  function looksLikePostEntity(el) {
    let score = 0;
    const id = entityId(el);
    if (id.startsWith("t3_")) score += 5;
    if (el.hasAttribute("post-title")) score += 3;
    const perm = el.getAttribute("permalink") || "";
    if (/^\/r\/[^/]+\/comments\/[^/]+(\/|$)/i.test(perm)) score += 3;
    if (el.hasAttribute("author")) score += 1;
    if (el.hasAttribute("created-timestamp")) score += 1;
    if (el.hasAttribute("subreddit-prefixed-name")) score += 1;
    if (el.hasAttribute("comment-count")) score += 1;
    return score >= 5;
  }

  function looksLikeCommentEntity(el) {
    let score = 0;
    const id = entityId(el);
    if (id.startsWith("t1_")) score += 5;
    const depthVal = el.getAttribute("depth");
    if (depthVal !== null && /^\d+$/.test(depthVal.trim())) score += 3;
    if (el.hasAttribute("author")) score += 1;
    if (el.hasAttribute("permalink")) score += 1;
    if (el.hasAttribute("created-timestamp")) score += 1;
    const ariaLabel = el.getAttribute("aria-label") || "";
    if (ariaLabel.startsWith("Comment from ")) score += 1;
    return score >= 5;
  }

  function isPromotedPost(el) {
    return el.hasAttribute("promoted")
      || (el.getAttribute("post-type") || "").toLowerCase() === "ad"
      || el.hasAttribute("is-promoted");
  }

  // ── Entity extraction ──

  function extractPostEntity(el) {
    const id = entityId(el);
    const permalink = el.getAttribute("permalink") || "";

    // Diagnostic: what element did we match?
    const attrs = {};
    for (const a of el.attributes || []) {
      attrs[a.name] = a.value.length > 200 ? a.value.slice(0, 200) + "..." : a.value;
    }
    debug.push({
      step: "extractPostEntity",
      tag: el.tagName,
      id_found: id,
      attr_keys: Object.keys(attrs).join(", "),
      attrs_sample: (({ "id": i, "thingid": t, "post-title": pt, "author": au, "score": sc, "permalink": pe, "depth": dp, "created-timestamp": ct, "comment-count": cc, "subreddit-prefixed-name": sn }) =>
        JSON.stringify({ id: i, thingid: t, post_title: pt, author: au, score: sc, permalink: pe, depth: dp, created_timestamp: ct, comment_count: cc, subreddit: sn }))(attrs),
      child_count: el.children.length,
      child_tags: [...el.children].slice(0, 10).map(c => c.tagName).join(", "),
      has_shadow: !!el.shadowRoot,
    });

    // Semantic attributes (old Reddit markup)
    let title = (el.getAttribute("post-title") || "").trim();
    let author = el.getAttribute("author") || "";
    let score = nullableInt(el.getAttribute("score"));
    let published = nullableStr(el.getAttribute("created-timestamp"));
    let body = extractEntityBody(el, id, "post");

    debug.push({
      step: "semantic_attrs",
      title_len: title.length, author, score, published: published ? published.slice(0, 30) : null,
      body_len: body.length,
    });

    // CSS-selector fallbacks (modern Reddit web components)
    if (!title) { title = extractTitleDOM(el); debug.push({ step: "fallback_title", title_len: title.length }); }
    if (!author) { author = extractAuthorDOM(el); debug.push({ step: "fallback_author", author }); }
    if (score === null) { score = extractScoreDOM(el); debug.push({ step: "fallback_score", score }); }
    if (!published) { published = extractPublishedDOM(el); debug.push({ step: "fallback_published", published }); }
    if (!body) {
      const tag = el.tagName;
      const hasShadow = !!el.shadowRoot;
      debug.push({ step: "fallback_body_attempt", el_tag: tag, has_shadow: hasShadow });
      body = extractPostBodyDOM(el);
      debug.push({ step: "fallback_body_result", body_len: body.length });
    }

    return {
      entity_type: "post",
      entity_id: id,
      canonical_url: absoluteRedditUrl(permalink),
      parent_id: null,
      author,
      score,
      published,
      body,
      sections: []
    };
  }

  function extractCommentEntity(el, depth) {
    const id = entityId(el);

    // Semantic attributes
    let author = el.getAttribute("author") || "";
    let score = nullableInt(el.getAttribute("score"));
    let published = nullableStr(el.getAttribute("created-timestamp"));
    let body = extractEntityBody(el, id, "comment");

    // CSS-selector fallbacks
    if (!author) author = extractAuthorDOM(el);
    if (score === null) score = extractScoreDOM(el);
    if (!published) published = extractPublishedDOM(el);
    if (!body) body = commentTextFallback(el);
    // If still empty, try DOM-based extraction
    if (!body) body = extractCommentBodyDOM(el);

    return {
      entity_type: depth === 0 ? "comment" : "reply",
      entity_id: id,
      canonical_url: absoluteRedditUrl(el.getAttribute("permalink") || ""),
      parent_id: null, // set by caller
      depth,
      author: el.getAttribute("author") || "",
      score: nullableInt(el.getAttribute("score")),
      published,
      body,
      sections: []
    };
  }

  // ── Comment tree (depth=0 top-level, depth=1 replies, >=2 ignored) ──

  function extractCommentTree(maxTopLevel) {
    const result = [];
    let topIndex = -1;
    let topCount = 0;

    for (const el of findCommentEntities()) {
      if (!looksLikeCommentEntity(el)) continue;

      const depth = commentDepth(el);
      if (depth === null) continue;

      if (depth === 0) {
        if (topCount >= maxTopLevel) break;
        const parsed = extractCommentEntity(el, 0);
        result.push(parsed);
        topIndex = result.length - 1;
        topCount++;
      } else if (depth === 1 && topIndex >= 0) {
        const reply = extractCommentEntity(el, 1);
        reply.parent_id = result[topIndex].entity_id;
        result.push(reply);
      }
      // depth >= 2 ignored
    }

    return result;
  }

  function commentDepth(el) {
    const val = (el.getAttribute("depth") || "").trim();
    return /^\d+$/.test(val) ? parseInt(val, 10) : null;
  }

  // ── Body extraction ──

  function extractEntityBody(el, thingId, kind) {
    if (thingId) {
      const suffix = kind === "comment" ? "-comment-rtjson-content" : "-post-rtjson-content";
      const target = document.getElementById(thingId + suffix);
      if (target) {
        const text = normalizeText(target.textContent);
        if (text) return text;
      }
    }

    const needles = kind === "comment"
      ? ["-comment-rtjson-content", "-post-rtjson-content"]
      : ["-post-rtjson-content"];

    for (const needle of needles) {
      const target = el.querySelector(`[id*="${CSS.escape(needle)}"]`);
      if (target) {
        const text = normalizeText(target.textContent);
        if (text) return text;
      }
    }

    return "";
  }

  function commentTextFallback(el) {
    const clone = el.cloneNode(true);
    // Remove nested comment entities
    const nested = [];
    const stack = [clone];
    while (stack.length) {
      const node = stack.pop();
      if (looksLikeDetachedCommentEntity(node)) {
        nested.push(node);
        continue;
      }
      for (const child of node.children) {
        stack.push(child);
      }
    }
    for (const n of nested) {
      n.parentNode?.removeChild(n);
    }
    return normalizeText(clone.textContent);
  }

  function looksLikeDetachedCommentEntity(el) {
    const id = entityId(el);
    if (id.startsWith("t1_")) return true;
    if (el.hasAttribute("depth") && el.hasAttribute("author")) return true;
    return (el.getAttribute("aria-label") || "").startsWith("Comment from ");
  }

  // ── DOM fallback extraction (modern Reddit web components) ──

  function extractTitleDOM(el) {
    // Try within the matched element, then page-level.
    const selectors = ["h1", "[slot='title']", "[data-testid='post-title']", "shreddit-post [slot='title']"];
    for (const sel of selectors) {
      const found = (el.querySelector(sel) || document.querySelector(sel));
      if (found) {
        const text = (found.textContent || "").trim();
        if (text) return text;
      }
    }
    // Last resort: document title minus Reddit branding.
    return document.title.replace(/^(Reddit - | : Reddit)/, "").trim();
  }

  function extractAuthorDOM(el) {
    const authorLink = (el.querySelector("a[href*='/user/']") || document.querySelector("a[href*='/user/']")
      || el.querySelector("[data-testid='post-author']") || el.querySelector("[data-testid='comment-author']"));
    if (authorLink) {
      const href = authorLink.getAttribute("href") || "";
      const text = (authorLink.textContent || "").trim();
      if (text.startsWith("u/")) return text;
      const match = href.match(/\/user\/([^/]+)/);
      if (match) return "u/" + match[1];
      if (text) return text;
    }
    return "";
  }

  function extractScoreDOM(el) {
    const scoreEl = el.querySelector("[score]") || el.querySelector("[data-testid='post-score']")
      || document.querySelector("shreddit-post [score]");
    if (scoreEl) {
      const val = scoreEl.getAttribute("score") || scoreEl.textContent || "";
      const num = parseInt(val.replace(/[^0-9-]/g, ""), 10);
      if (!isNaN(num)) return num;
    }
    return null;
  }

  function extractPublishedDOM(el) {
    const timeEl = el.querySelector("time[datetime]") || document.querySelector("shreddit-post time[datetime]");
    if (timeEl) return timeEl.getAttribute("datetime") || null;
    return null;
  }

  function extractPostBodyDOM(el) {
    // Try shadow DOM for shreddit-post custom element.
    if (el.tagName === "SHREDDIT-POST" && el.shadowRoot) {
      const content = el.shadowRoot.querySelector("[slot='post-content']")
        || el.shadowRoot.querySelector("[data-testid='post-content']")
        || el.shadowRoot.querySelector(".md");
      if (content) {
        const text = normalizeText(content.textContent);
        if (text) return text;
      }
    }

    // Try light-DOM selectors.
    const contentSelectors = [
      "[data-testid='post-content']", "[slot='post-content']",
      ".md", "[slot='body']", ".post-content", ".entry",
    ];
    for (const sel of contentSelectors) {
      const found = el.querySelector(sel);
      if (found) {
        const text = normalizeText(found.textContent);
        if (text.length > 20) return text;
      }
    }

    // Fallback: full text content minus nested comment entities.
    const clone = el.cloneNode(true);
    for (const nested of clone.querySelectorAll("shreddit-comment, [id^='t1_'], [thingid^='t1_']")) {
      nested.remove();
    }
    return normalizeText(clone.textContent);
  }

  function extractCommentBodyDOM(el) {
    // Try shadow DOM for shreddit-comment.
    if (el.tagName === "SHREDDIT-COMMENT" && el.shadowRoot) {
      const content = el.shadowRoot.querySelector("[slot='comment-body']")
        || el.shadowRoot.querySelector("[data-testid='comment-body']")
        || el.shadowRoot.querySelector(".md");
      if (content) {
        const text = normalizeText(content.textContent);
        if (text) return text;
      }
    }

    // Light-DOM fallbacks.
    const bodySelectors = [
      "[data-testid='comment-body']", "[slot='comment-body']",
      ".md", ".comment-content", ".usertext-body",
    ];
    for (const sel of bodySelectors) {
      const found = el.querySelector(sel);
      if (found) {
        const text = normalizeText(found.textContent);
        if (text.length > 5) return text;
      }
    }

    return "";
  }

  // ── Helpers ──

  function entityId(el) {
    const id = (el.getAttribute("id") || "").trim();
    const thingId = (el.getAttribute("thingid") || "").trim();
    if (id.startsWith("t1_") || id.startsWith("t3_")) return id;
    if (thingId.startsWith("t1_") || thingId.startsWith("t3_")) return thingId;
    return id || thingId;
  }

  function isPostUrl(url) {
    return /^\/r\/[^/]+\/comments\/[^/]+(\/|$)/i.test(new URL(url).pathname);
  }

  function postThingIdFromUrl(url) {
    const m = new URL(url).pathname.match(/^\/r\/[^/]+\/comments\/([^/]+)/i);
    return m ? "t3_" + m[1] : null;
  }

  function normalizeRedditPath(path) {
    return "/" + path.replace(/^\/+|\/+$/g, "") + "/";
  }

  function absoluteRedditUrl(url) {
    if (!url) return "";
    if (/^https?:\/\//i.test(url)) return url;
    return "https://www.reddit.com/" + url.replace(/^\//, "");
  }

  function nullableInt(val) {
    const s = (val || "").trim();
    return /^-?\d+$/.test(s) ? parseInt(s, 10) : null;
  }

  function nullableStr(val) {
    const s = (val || "").trim();
    return s || null;
  }

  function normalizeText(text) {
    return (text || "")
      .replace(/\u00A0/g, " ")
      .replace(/[ \t]+/g, " ")
      .replace(/\s*\n\s*/g, "\n")
      .replace(/\n{3,}/g, "\n\n")
      .trim();
  }

  // ── XPath helper (returns Element[]) ──

  function xpathElements(expr, contextNode) {
    const result = [];
    const iter = document.evaluate(
      expr,
      contextNode || document,
      null,
      XPathResult.ORDERED_NODE_ITERATOR_TYPE,
      null
    );
    let node;
    while ((node = iter.iterateNext())) {
      if (node.nodeType === Node.ELEMENT_NODE) result.push(node);
    }
    return result;
  }
})();

// ── CAPTCHA poll listener — re-checks page state while service worker waits ──
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg?.type === "localsy_fetch_poll") {
    const ch = detectChallenge();
    if (ch) {
      sendResponse({ type: "localsy_fetch_poll_result", status: ch });
    } else {
      // Page resolved — report no-challenge. Service worker will re-navigate
      // (which re-triggers this content script) or just finishes.
      sendResponse({ type: "localsy_fetch_poll_result", status: "resolved" });
    }
  }
});

function detectChallenge() {
  if (/prove your humanity/i.test(document.title)) return "challenge_required";
  if (document.querySelector("iframe[src*='recaptcha']")) return "challenge_required";
  if (location.hostname === "consent.google.com") return "consent_required";
  if (location.pathname.startsWith("/sorry/")) return "challenge_required";
  return null;
}

function isPrivateHost(hostname) {
  const h = hostname.toLowerCase();
  if (h === "localhost" || h.endsWith(".localhost")) return true;
  if (h === "[::1]" || h === "::1") return true;
  if (h === "0.0.0.0" || h === "255.255.255.255") return true;
  if (/^(?:127\.|10\.|192\.168\.|169\.254\.)/.test(h)) return true;
  if (/^172\.(?:1[6-9]|2\d|3[01])\./.test(h)) return true;
  if (h.endsWith(".local") || h.endsWith(".internal")) return true;
  return false;
}
