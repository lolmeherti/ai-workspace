// Localsy extraction-compare harness — snapshot capture + compact DOM map builder.
//
// Runs as a content script in the harness extension on every page, but only acts
// when the service worker sends "localsy_capture_build" (i.e. during a capture
// job, after the unmodified production extractor has settled).
//
// Deterministic rules only. Nothing here calls a model.
//
// Output payload:
//   map        — compact, budget-aware-able DOM map with stable snapshot-local IDs
//   store      — full underlying content per ID (preview truncation never destroys it)
//   limitations— what this capture could not see (frames, shadow DOM, hidden text, caps)
//   dom_html   — serialized rendered DOM for the record (byte-capped, flagged)

(() => {
  "use strict";

  const OPTIONS = {
    previewChars: 260,        // 1–2 sentences, hard character cap
    minBlockChars: 6,         // below this a text block is considered noise (25 hid a 16-char salary line)
    minRegionChars: 200,      // a region must carry at least this much text
    regionOwnTextChars: 100,  // ...or this much own (non-descendant) text
    regionChildBlocks: 2,     // ...or this many included descendant blocks
    linkCueMax: 3,
    linkTextMax: 60,
    linkHrefMax: 140,
    tableHeaderMax: 8,
    storeMaxCharsPerBlock: 40000,
    domMaxBytes: 8000000,
    keepChrome: true,         // keep nav/header/footer/aside as tagged blocks
    // Never let the character floor throw away a VALUE. A 15-char "mind. 60.000EUR"
    // was dropped by a 25-char floor; anything carrying a digit or a currency/percent
    // sign is kept as its own block regardless of length.
    keepValueShaped: true
  };

  const TEXT_TAGS = new Set(["P", "LI", "H1", "H2", "H3", "H4", "H5", "H6", "BLOCKQUOTE",
    "PRE", "DD", "DT", "FIGCAPTION", "ADDRESS", "SUMMARY", "TABLE"]);
  const REGION_TAGS = new Set(["ARTICLE", "SECTION", "MAIN", "ASIDE", "NAV", "HEADER",
    "FOOTER", "FORM", "FIGURE", "DETAILS", "DIV"]);
  const NOISE_TAGS = new Set(["SCRIPT", "STYLE", "NOSCRIPT", "TEMPLATE", "SVG", "CANVAS",
    "IFRAME", "OBJECT", "EMBED", "VIDEO", "AUDIO", "MAP", "LINK", "META"]);
  const CHROME_TAGS = new Set(["NAV", "HEADER", "FOOTER", "ASIDE"]);

  // Elements whose text belongs to themselves — parents must not repeat it.
  function isBoundary(el) {
    const tag = el.tagName;
    return TEXT_TAGS.has(tag) || REGION_TAGS.has(tag) || tag === "HR";
  }

  function norm(s) {
    return String(s == null ? "" : s).replace(/\s+/g, " ").trim();
  }

  // Text directly owned by this element: its own text nodes plus inline
  // descendants, but never the text of a nested text-block or region block.
  // Spaces are inserted only between alphanumeric runs so own_len never exceeds
  // total_len through purely synthetic whitespace.
  function ownText(el) {
    let out = "";
    const push = (text) => {
      const t = String(text || "");
      if (!t) return;
      if (out && /[A-Za-z0-9]$/.test(out) && /^[A-Za-z0-9]/.test(t)) out += " ";
      out += t;
    };
    const walk = (node) => {
      for (const child of node.childNodes) {
        if (child.nodeType === Node.TEXT_NODE) {
          push(child.textContent);
          continue;
        }
        if (child.nodeType !== Node.ELEMENT_NODE) continue;
        const tag = child.tagName;
        if (NOISE_TAGS.has(tag)) continue;
        if (child !== el && isBoundary(child)) continue;
        if (tag === "BR") { push(" "); continue; }
        walk(child);
      }
    };
    walk(el);
    return norm(out);
  }

  function totalText(el) {
    return norm(el.textContent);
  }

  function isHidden(el) {
    try {
      const style = getComputedStyle(el);
      if (style.display === "none" || style.visibility === "hidden" || style.opacity === "0") return true;
      if (el.hasAttribute("hidden") || el.getAttribute("aria-hidden") === "true") return true;
    } catch (e) { /* detached node */ }
    return false;
  }

  function roleOf(el) {
    const explicit = el.getAttribute("role");
    if (explicit) return explicit;
    switch (el.tagName) {
      case "NAV": return "navigation";
      case "HEADER": return "banner";
      case "FOOTER": return "contentinfo";
      case "ASIDE": return "complementary";
      case "MAIN": return "main";
      case "ARTICLE": return "article";
      case "SECTION": return "region";
      case "FORM": return "form";
      case "TABLE": return "table";
      case "UL": case "OL": return "list";
      case "H1": case "H2": case "H3": case "H4": case "H5": case "H6": return "heading";
      case "PRE": return "code";
      case "BLOCKQUOTE": return "blockquote";
      case "DETAILS": return "details";
      case "FIGURE": return "figure";
      default: return null;
    }
  }

  function firstSentences(text, maxChars) {
    if (!text) return "";
    let end = -1;
    let sentences = 0;
    for (let i = 0; i < text.length && i < maxChars * 3; i++) {
      const c = text[i];
      if ((c === "." || c === "!" || c === "?") && (i + 1 >= text.length || /\s/.test(text[i + 1]))) {
        sentences++;
        if (sentences >= 2) { end = i + 1; break; }
      }
    }
    let slice = end > 0 ? text.slice(0, end) : text;
    let truncated = false;
    if (slice.length > maxChars) { slice = slice.slice(0, maxChars); truncated = true; }
    if (end > 0 && end < text.length) truncated = true;
    return slice.trim() + (truncated ? " …[+" + (text.length - slice.trim().length) + " chars]" : "");
  }

  function absUrl(href) {
    try { return new URL(href, location.href).toString(); } catch (e) { return String(href || ""); }
  }

  function tableInfo(el) {
    const rows = el.querySelectorAll("tr").length;
    let cols = 0;
    for (const tr of el.querySelectorAll("tr")) {
      cols = Math.max(cols, tr.children.length);
    }
    const headers = [];
    const headRow = el.querySelector("tr");
    if (headRow) {
      for (const cell of headRow.children) {
        headers.push(norm(cell.textContent).slice(0, 80));
        if (headers.length >= OPTIONS.tableHeaderMax) break;
      }
    }
    return { rows, cols, headers };
  }

  function linkCues(el, limit) {
    const cues = [];
    if (limit <= 0) return cues;
    for (const a of el.querySelectorAll("a[href]")) {
      const text = norm(a.textContent);
      if (!text) continue;
      cues.push({ text: text.slice(0, OPTIONS.linkTextMax), href: absUrl(a.getAttribute("href")).slice(0, OPTIONS.linkHrefMax) });
      if (cues.length >= limit) break;
    }
    return cues;
  }

  // ── Markdown rendering for the content store (deterministic, structure-preserving)
  function mdOf(node, depth) {
    let out = "";
    depth = depth || 0;
    for (const child of node.childNodes) {
      if (child.nodeType === Node.TEXT_NODE) { out += child.textContent.replace(/\s+/g, " "); continue; }
      if (child.nodeType !== Node.ELEMENT_NODE) continue;
      const tag = child.tagName;
      if (NOISE_TAGS.has(tag)) continue;
      switch (tag) {
        case "BR": out += "\n"; break;
        case "HR": out += "\n\n---\n\n"; break;
        case "H1": case "H2": case "H3": case "H4": case "H5": case "H6":
          out += "\n\n" + "#".repeat(parseInt(tag.charAt(1), 10)) + " " + norm(child.textContent) + "\n\n";
          break;
        case "P":
          out += "\n\n" + mdOf(child, depth + 1) + "\n\n";
          break;
        case "UL": case "OL":
          out += "\n" + listMd(child, tag === "OL") + "\n";
          break;
        case "TABLE":
          out += "\n\n" + tableMd(child) + "\n\n";
          break;
        case "PRE":
          out += "\n\n```\n" + String(child.textContent || "").replace(/\s+$/, "") + "\n```\n\n";
          break;
        case "BLOCKQUOTE":
          out += "\n" + mdOf(child, depth + 1).trim().split("\n")
            .map((line) => "> " + line).join("\n") + "\n";
          break;
        case "A": {
          const text = norm(child.textContent);
          if (!text) break;
          const href = child.getAttribute("href");
          out += href ? "[" + text + "](" + absUrl(href) + ")" : text;
          break;
        }
        case "IMG": {
          const alt = norm(child.getAttribute("alt"));
          if (alt) out += "![" + alt + "]";
          break;
        }
        default:
          out += mdOf(child, depth + 1);
      }
    }
    return out;
  }

  function listMd(listEl, ordered) {
    const lines = [];
    let i = 1;
    for (const li of listEl.children) {
      if (li.tagName !== "LI") continue;
      const body = mdOf(li, 1).trim().replace(/\n{2,}/g, "\n  ");
      lines.push((ordered ? i++ + ". " : "- ") + body);
    }
    return lines.join("\n");
  }

  function tableMd(tableEl) {
    const rows = [];
    for (const tr of tableEl.querySelectorAll("tr")) {
      const cells = [];
      for (const cell of tr.children) {
        if (cell.tagName === "TH" || cell.tagName === "TD") {
          cells.push(norm(cell.textContent).replace(/\|/g, "\\|"));
        }
      }
      if (cells.length) rows.push(cells);
    }
    if (!rows.length) return "";
    const width = Math.max(...rows.map((r) => r.length));
    const pad = (r) => { const a = r.slice(); while (a.length < width) a.push(""); return a; };
    const head = pad(rows[0].slice());
    const lines = ["| " + head.join(" | ") + " |", "| " + head.map(() => "---").join(" | ") + " |"];
    for (let i = 1; i < rows.length; i++) lines.push("| " + pad(rows[i].slice()).join(" | ") + " |");
    return lines.join("\n");
  }

  function tidy(md) {
    return String(md || "").replace(/[ \t]+\n/g, "\n").replace(/\n{3,}/g, "\n\n").trim();
  }

  // ── Snapshot capture
  async function build() {
    const tMapStart = Date.now();

    // Give the page one more quiet moment; readiness was already established by
    // the production extractor's own settle loop.
    await new Promise((r) => setTimeout(r, 250));

    const skipped = { chrome: 0, noise: 0, small_text_blocks: 0, small_regions: 0, hidden: 0 };
    const included = new Set();
    const valueShaped = new Set();
    const droppedSamples = [];
    let droppedChars = 0;
    let valueKept = 0;

    // Pass A — text-bearing blocks.
    for (const el of document.body.querySelectorAll("*")) {
      const tag = el.tagName;
      if (NOISE_TAGS.has(tag) || tag === "TD" || tag === "TH" || tag === "CAPTION") { skipped.noise++; continue; }
      if (el.closest("table") && tag !== "TABLE") { continue; }  // cells live inside the table block
      if (!TEXT_TAGS.has(tag)) continue;
      if (!OPTIONS.keepChrome && (el.closest("nav") || el.closest("header") || el.closest("footer") || el.closest("aside"))) { skipped.chrome++; continue; }
      const text = totalText(el);
      const isHeading = tag.length === 2 && tag[0] === "H";
      if (!text.length) { skipped.small_text_blocks++; continue; }
      const isValue = /(\d|[€$%])/.test(text);
      const tooSmall = text.length < OPTIONS.minBlockChars && !isHeading && tag !== "TABLE";
      if (tooSmall && !(OPTIONS.keepValueShaped && isValue)) {
        skipped.small_text_blocks++;
        droppedChars += text.length;
        if (droppedSamples.length < 200) droppedSamples.push({ tag, text: text.slice(0, 120) });
        continue;
      }
      if (tooSmall && isValue) valueKept++;
      if (isValue) valueShaped.add(el);
      included.add(el);
    }

    // Pass B — significant regions only (collapses redundant wrappers).
    for (const el of document.body.querySelectorAll("*")) {
      const tag = el.tagName;
      if (!REGION_TAGS.has(tag)) continue;
      if (!OPTIONS.keepChrome && CHROME_TAGS.has(tag)) { skipped.chrome++; continue; }
      if (el.closest("table")) continue;
      const total = totalText(el);
      if (!total.length) { skipped.small_regions++; continue; }
      if (total.length < OPTIONS.minRegionChars) { skipped.small_regions++; continue; }
      if (ownText(el).length < OPTIONS.regionOwnTextChars) {
        const childBlocks = el.querySelectorAll(
          "p,li,h1,h2,h3,h4,h5,h6,blockquote,pre,dd,dt,figcaption,summary,table"
        ).length;
        if (childBlocks < OPTIONS.regionChildBlocks) { skipped.small_regions++; continue; }
      }
      included.add(el);
    }

    // Document order + stable snapshot-local IDs.
    const ordered = [];
    const rewalk = () => {
      ordered.length = 0;
      const w = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
      let n = w.currentNode;
      while (n) {
        if (included.has(n)) ordered.push(n);
        n = w.nextNode();
      }
    };
    rewalk();

    // Collapse redundant wrappers: a region with no own text that wraps exactly
    // one included child carrying essentially all of its text adds nothing the
    // child does not already offer. Repeat until stable.
    const nearestIncluded = (el) => {
      for (let p = el.parentElement; p; p = p.parentElement) if (included.has(p)) return p;
      return null;
    };
    for (let pass = 0; pass < 5; pass++) {
      const childrenOf = new Map();
      for (const el of ordered) {
        const parent = nearestIncluded(el);
        if (!parent) continue;
        if (!childrenOf.has(parent)) childrenOf.set(parent, []);
        childrenOf.get(parent).push(el);
      }
      const drop = new Set();
      for (const el of ordered) {
        if (!REGION_TAGS.has(el.tagName)) continue;
        if (ownText(el).length > 0) continue;
        const kids = childrenOf.get(el) || [];
        if (kids.length !== 1) continue;
        if (totalText(kids[0]).length >= totalText(el).length * 0.98) drop.add(el);
      }
      if (!drop.size) break;
      for (const el of drop) included.delete(el);
      rewalk();
    }

    const idOf = new Map();
    ordered.forEach((el, i) => idOf.set(el, "e" + (i + 1)));

    const blocks = [];
    const store = {};
    let leafCount = 0;
    let regionCount = 0;

    for (const el of ordered) {
      const id = idOf.get(el);
      const tag = el.tagName;
      const isRegion = REGION_TAGS.has(tag);

      // Nearest included ancestor → lets the validator dedupe parent/child picks.
      let parentId = null;
      for (let p = el.parentElement; p; p = p.parentElement) {
        if (idOf.has(p)) { parentId = idOf.get(p); break; }
      }
      let depth = 0;
      for (let p = el.parentElement; p; p = p.parentElement) if (idOf.has(p)) depth++;

      const own = ownText(el);
      const total = totalText(el);
      const info = tag === "TABLE" ? tableInfo(el) : null;

      let preview;
      if (tag === "TABLE") {
        preview = "table: " + info.rows + " rows × " + info.cols + " cols; header row: "
          + (info.headers.length ? info.headers.join(" | ") : "(none)");
      } else if (isRegion && own.length < 10) {
        const childBlocks = el.querySelectorAll(
          "p,li,h1,h2,h3,h4,h5,h6,blockquote,pre,dd,dt,figcaption,summary,table"
        ).length;
        preview = "(container — no own text; " + childBlocks + " descendant blocks)";
      } else {
        preview = firstSentences(own.length >= (isRegion ? 10 : 0) ? own : total, OPTIONS.previewChars);
      }

      const links = (!isRegion || total.length <= 4000) ? linkCues(el, OPTIONS.linkCueMax) : [];
      if (isRegion) regionCount++; else leafCount++;

      blocks.push({
        id, parent_id: parentId, depth, tag, role: roleOf(el),
        own_len: own.length, total_len: total.length,
        preview, table: info, links,
        hidden: isHidden(el) || undefined,
        value_shape: valueShaped.has(el) || undefined
      });

      // Full content for this block — preview truncation above never destroys it.
      let markdown = tidy(mdOf(el, 0));
      let capped = false;
      if (markdown.length > OPTIONS.storeMaxCharsPerBlock) {
        markdown = markdown.slice(0, OPTIONS.storeMaxCharsPerBlock);
        capped = true;
      }
      store[id] = { tag, role: roleOf(el), text_len: total.length, markdown_len: markdown.length, capped, markdown };
    }

    const map = {
      snapshot: {
        url: location.href,
        title: document.title,
        captured_at: new Date().toISOString(),
        ready_state: document.readyState
      },
      options: OPTIONS,
      counts: {
        included_blocks: blocks.length,
        text_blocks: leafCount,
        region_blocks: regionCount,
        value_shaped_kept_below_floor: valueKept,
        skipped
      },
      // Everything the character floor still refused, so the artifact can be audited
      // instead of trusted. Content behind these is still reachable through an
      // included ancestor's stored markdown (the store has no floor).
      dropped_text_blocks: {
        count: skipped.small_text_blocks,
        chars: droppedChars,
        samples: droppedSamples,
        samples_truncated: skipped.small_text_blocks > droppedSamples.length
      },
      blocks
    };

    const mapBuildMs = Date.now() - tMapStart;

    const tSer = Date.now();
    const html = "<!DOCTYPE html>\n" + document.documentElement.outerHTML;
    const domTruncated = html.length > OPTIONS.domMaxBytes;
    const domHtml = domTruncated ? html.slice(0, OPTIONS.domMaxBytes) : html;
    const serMs = Date.now() - tSer;

    const bodyTextLen = (document.body.textContent || "").length;
    let visibleLen = 0;
    try { visibleLen = (document.body.innerText || "").length; } catch (e) { visibleLen = 0; }

    const limitations = {
      viewport: { width: window.innerWidth, height: window.innerHeight },
      scroll_y: window.scrollY,
      page_height: document.documentElement.scrollHeight,
      iframes: document.querySelectorAll("iframe").length,
      iframe_content_read: false,
      shadow_hosts: document.querySelectorAll("*").length
        ? Array.from(document.querySelectorAll("*")).filter((e) => e.shadowRoot).length : 0,
      shadow_dom_read: false,
      scripts: document.querySelectorAll("script").length,
      textcontent_chars: bodyTextLen,
      innertext_chars: visibleLen,
      hidden_text_chars: Math.max(0, bodyTextLen - visibleLen),
      images_pending: Array.from(document.images || []).filter((i) => !i.complete).length,
      dom_html_bytes: html.length,
      dom_html_truncated: domTruncated,
      dom_html_cap_bytes: OPTIONS.domMaxBytes,
      scroll_triggered: false,
      pagination_followed: false,
      notes: [
        "Map is built from the live DOM after the production extractor settled; hidden text inside included blocks is counted in total_len (textContent based).",
        "No scrolling, no clicks (consent banner handled by the production extractor), no iframe or shadow-DOM traversal.",
        "Preview text uses each block's own text only; descendant text is never repeated under an ancestor."
      ]
    };

    return {
      ok: true,
      payload: {
        map,
        store,
        limitations,
        timings: { map_build_ms: mapBuildMs, dom_serialize_ms: serMs },
        dom_html: domHtml,
        dom_html_truncated: domTruncated
      }
    };
  }

  chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg?.type !== "localsy_capture_build") return;
    // Per-capture overrides (compare.php → server job → here) so a knob can change
    // and be retested without editing this file. Recorded in dom-map.json options.
    if (msg.options && typeof msg.options === "object") {
      for (const key of Object.keys(msg.options)) {
        if (Object.prototype.hasOwnProperty.call(OPTIONS, key) && msg.options[key] !== null) {
          OPTIONS[key] = msg.options[key];
        }
      }
    }
    build()
      .then((payload) => sendResponse(payload))
      .catch((e) => sendResponse({ ok: false, error: String((e && e.stack) || e) }));
    return true; // async response
  });
})();
