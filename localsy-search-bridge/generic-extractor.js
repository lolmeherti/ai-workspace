// Localsy generic page extractor — content script for all URLs not covered
// by site-specific extractors. Walks the visible DOM, preserves heading hierarchy
// (h1–h6), skips script/style/nav/noscript elements, and returns a single
// "article" entity with sections derived from headings.
//
// SSRF defense layers (outer to inner):
//   L1  PHP: scheme/port/credential checks (BridgeFetcher::validateFetchUrl)
//   L2  Go:  DNS resolve → reject non-global-unicast IPs (relay.go validateHost)
//   L3  DNR: declarativeNetRequest blocks main_frame nav to literal private IPs
//   L4  JS:  isPrivateHost checks location.hostname before extraction (below)
//   Known gap: DNS rebinding between L2 resolve and Edge navigation.
//   For a single-user search agent, this residual window is acceptable.
(async () => {
  if (window.__localsy_extracted) return;

  // Authorization probe
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

  // ── Challenge detection ──
  const challenge = detectChallenge();
  if (challenge) {
    await chrome.runtime.sendMessage({
      type: "localsy_fetch_result",
      status: challenge,
      content: null
    }).catch(() => null);
    return;
  }

  // ── Extract with polling — SPA content may not be rendered yet ──
  let extraction = extract();
  const deadline = Date.now() + 10_000;

  while (Date.now() < deadline && extraction.body.length < 200) {
    await new Promise(r => setTimeout(r, 500));
    extraction = extract();
  }

  await chrome.runtime.sendMessage({
    type: "localsy_fetch_result",
    status: "success",
    content: {
      url: location.href,
      title: document.title,
      fetched_at: new Date().toISOString(),
      date_posted: extractJsonLdDatePosted(),
      links: extraction.links,
      entities: [{
        entity_type: "article",
        entity_id: hashURL(location.href),
        canonical_url: location.href,
        parent_id: null,
        body: extraction.body,
        sections: extraction.sections
      }]
    }
  }).catch(() => null);

  // ═══════════════════════════════════════════════════
  // Challenge detection
  // ═══════════════════════════════════════════════════
  function detectChallenge() {
    const title = document.title || "";
    if (/prove your humanity|just a moment|checking your browser|unusual (traffic|activity)|attention required|verify you are human|enable javascript/i.test(title)) {
      return "challenge_required";
    }
    if (document.querySelector("iframe[src*='recaptcha'], iframe[src*='hcaptcha'], iframe[src*='captcha']")) {
      return "challenge_required";
    }
    if (location.hostname === "consent.google.com") return "consent_required";
    if (location.pathname.startsWith("/sorry/")) return "challenge_required";

    const bodyText = (document.body && document.body.innerText || "").slice(0, 2000);
    if (/unusual (traffic|activity)|checking your browser before accessing/i.test(bodyText)) {
      return "challenge_required";
    }
    return null;
  }

  // ═══════════════════════════════════════════════════
  // DOM extraction — heading-preserving text walk
  // ═══════════════════════════════════════════════════
  function extract() {
    const sections = [];
    let currentHeading = "";
    let currentLevel = 0;
    let buffer = "";
    let fullBody = "";

    const SKIP_TAGS = new Set(["SCRIPT", "STYLE", "NAV", "NOSCRIPT", "IFRAME", "SVG", "TEMPLATE"]);
    const HEADING_TAGS = new Set(["H1", "H2", "H3", "H4", "H5", "H6"]);

    const walker = document.createTreeWalker(
      document.body,
      NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT,
      {
        acceptNode: (node) => {
          if (node.nodeType === Node.TEXT_NODE) {
            return NodeFilter.FILTER_ACCEPT;
          }
          const tag = node.tagName;
          if (!tag) return NodeFilter.FILTER_SKIP;
          if (SKIP_TAGS.has(tag)) return NodeFilter.FILTER_REJECT;
          // Accept all other elements so we can detect headings.
          return NodeFilter.FILTER_ACCEPT;
        }
      }
    );

    const seenText = new Set();

    let node;
    while ((node = walker.nextNode())) {
      if (node.nodeType === Node.ELEMENT_NODE && HEADING_TAGS.has(node.tagName)) {
        // Flush current buffer as a section, start new heading.
        flushSection();
        currentHeading = cleanText(node.textContent);
        currentLevel = parseInt(node.tagName.charAt(1), 10);
        continue;
      }

      if (node.nodeType === Node.TEXT_NODE) {
        const text = cleanText(node.textContent);
        if (!text || seenText.has(text)) continue;
        seenText.add(text);

        if (buffer) buffer += " ";
        buffer += text;
      }
    }

    // Flush trailing buffer.
    flushSection();

    // Assemble full body from all sections.
    for (const sec of sections) {
      if (sec.heading) {
        fullBody += (fullBody ? "\n\n" : "") + "#".repeat(sec.heading_level) + " " + sec.heading + "\n\n" + sec.body;
      } else {
        fullBody += (fullBody ? "\n\n" : "") + sec.body;
      }
    }

    // If TreeWalker produced nothing (SPA, shadow DOM), fall back to innerText.
    if (!fullBody.trim()) {
      fullBody = cleanText(document.body.innerText);
    }

    // Collect visible anchor links (absolute URLs, deduped, bounded).
    const links = [];
    const seenLinks = new Set();
    for (const a of document.querySelectorAll('a[href]')) {
      const href = a.href || '';
      if (!href || href.startsWith('javascript:') || href.startsWith('mailto:')
          || href.startsWith('tel:') || href.startsWith('#')) continue;
      const text = cleanText(a.textContent);
      if (!text) continue;
      const key = href.split('#')[0];
      if (seenLinks.has(key)) continue;
      seenLinks.add(key);
      links.push({ url: href, text });
      if (links.length >= 500) break;
    }

    return { body: fullBody.slice(0, 120000), sections, links };

    function flushSection() {
      const b = buffer.trim();
      buffer = "";
      if (!b) return;
      sections.push({
        heading: currentHeading,
        heading_level: currentHeading ? currentLevel : 0,
        body: b
      });
      currentHeading = "";
    }
  }

  function cleanText(value) {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function hashURL(url) {
    // Simple deterministic hash — not cryptographic, just for entity_id uniqueness.
    let hash = 0;
    for (let i = 0; i < url.length; i++) {
      hash = ((hash << 5) - hash) + url.charCodeAt(i);
      hash |= 0;
    }
    return "gen_" + Math.abs(hash).toString(36);
  }

  // Generic schema.org JSON-LD scrape: return the first datePosted string found
  // (JobPosting is the common case), regardless of host. No site-specific logic.
  function extractJsonLdDatePosted() {
    try {
      for (const node of document.querySelectorAll('script[type="application/ld+json"]')) {
        let data;
        try {
          data = JSON.parse(node.textContent || '');
        } catch (e) {
          continue;
        }
        const found = findDatePosted(data);
        if (found) return found;
      }
    } catch (e) {}
    return null;
  }

  function findDatePosted(node) {
    if (node == null) return null;
    if (Array.isArray(node)) {
      for (const item of node) {
        const found = findDatePosted(item);
        if (found) return found;
      }
      return null;
    }
    if (typeof node === 'object') {
      if (typeof node.datePosted === 'string' && node.datePosted.trim() !== '') {
        return node.datePosted.trim();
      }
      for (const key of Object.keys(node)) {
        const found = findDatePosted(node[key]);
        if (found) return found;
      }
    }
    return null;
  }
})();

// CAPTCHA poll listener
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg?.type === "localsy_fetch_poll") {
    const ch = detectChallenge();
    if (ch) {
      sendResponse({ type: "localsy_fetch_poll_result", status: ch });
    } else {
      sendResponse({ type: "localsy_fetch_poll_result", status: "resolved" });
    }
  }
});

function detectChallenge() {
  const title = document.title || "";
  if (/prove your humanity|just a moment|checking your browser|unusual (traffic|activity)|attention required|verify you are human|enable javascript/i.test(title)) {
    return "challenge_required";
  }
  if (document.querySelector("iframe[src*='recaptcha'], iframe[src*='hcaptcha'], iframe[src*='captcha']")) {
    return "challenge_required";
  }
  if (location.hostname === "consent.google.com") return "consent_required";
  if (location.pathname.startsWith("/sorry/")) return "challenge_required";

  const bodyText = (document.body && document.body.innerText || "").slice(0, 2000);
  if (/unusual (traffic|activity)|checking your browser before accessing/i.test(bodyText)) {
    return "challenge_required";
  }
  return null;
}

// Check whether the browser landed on a private/local IP after redirect.
// Called from the IIFE above before extraction begins.
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
