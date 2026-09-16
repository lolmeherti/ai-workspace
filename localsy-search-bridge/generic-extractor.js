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

  // Authorization probe (also carries cf-mitigated for this tab's top-level nav)
  const probe = await chrome.runtime.sendMessage({ type: "localsy_fetch_probe" }).catch(() => null);
  if (!probe?.allowed) return;
  const cfMitigated = (probe?.cf_mitigated || "").toLowerCase();

  // Redirect safety — reject if the browser landed on a private IP
  if (isPrivateHost(location.hostname)) {
    await chrome.runtime.sendMessage({
      type: "localsy_fetch_result",
      status: "rejected_redirect",
      content: { _redirect_target: location.href }
    }).catch(() => null);
    return;
  }

  // ── Wait for the top-level navigation to finish loading ──
  await waitForLoad(20_000);

  // ── Consent: dismiss a cookie banner so its text doesn't pollute content ──
  // "stored" = the site already has a consent decision on file (no banner);
  // otherwise click "accept all" when a recognized banner is present.
  const consentState = await handleConsent();

  // ── Terminal statuses — don't extract challenge/consent screens ──
  if (detectConsent()) {
    await sendResult("consent_required", { _consent: consentState });
    return;
  }
  const hardChallenge = detectHardChallenge();
  if (hardChallenge) {
    await sendResult("challenge_required", { _challenge_reason: hardChallenge, _consent: consentState });
    return;
  }

  // ── Extraction with stability + quality-gate early exit ──
  // Run the REAL extractor candidate repeatedly. Early-exit only when the
  // candidate (a) passes the content gate, (b) is unchanged across two short
  // samples, and (c) no challenge/consent blocker is active. Whole-page text can
  // keep mutating from ads/widgets after the article is done, so we compare the
  // extracted candidate — not document.body.innerText.
  const out = await extractUntilSettled(cfMitigated);

  if (out.terminal) {
    await sendResult(out.terminal, { _challenge_reason: out.reason, _consent: consentState, _debug: out.debug });
    return;
  }

  const finalCandidate = out.candidate || { body: "", sections: [], links: [] };
  await sendResult("success", {
    url: location.href,
    title: document.title,
    fetched_at: new Date().toISOString(),
    date_posted: extractJsonLdDatePosted(),
    links: finalCandidate.links,
    entities: [{
      entity_type: "article",
      entity_id: hashURL(location.href),
      canonical_url: location.href,
      parent_id: null,
      body: finalCandidate.body,
      sections: finalCandidate.sections
    }],
    _debug: { ...out.debug, consent: consentState, body_len: finalCandidate.body.length, early_exit: out.stable }
  });

  // ═══════════════════════════════════════════════════════════
  // Helpers: load wait, consent, challenge, extraction
  // ═══════════════════════════════════════════════════════════

  function sleep(ms) {
    return new Promise(r => setTimeout(r, ms));
  }

  async function sendResult(status, content) {
    await chrome.runtime.sendMessage({ type: "localsy_fetch_result", status, content }).catch(() => null);
  }

  async function waitForLoad(timeoutMs) {
    if (document.readyState === 'complete') return true;
    return await new Promise((resolve) => {
      let done = false;
      const finish = (ok) => {
        if (done) return;
        done = true;
        clearTimeout(timer);
        window.removeEventListener('load', onLoad);
        resolve(ok);
      };
      const timer = setTimeout(() => finish(false), timeoutMs);
      const onLoad = () => finish(true);
      window.addEventListener('load', onLoad);
      if (document.readyState === 'complete') finish(true); // race guard
    });
  }

  // ── Consent detection / dismissal ──
  // A stored consent decision (cookie/localStorage) means the site has already
  // passed its consent gate — no banner, no click, and we can trust a fast stable
  // page sooner. (We don't assume it means "accept all", just "already decided".)
  function detectStoredConsent() {
    try {
      if (/OptanonConsent|OptanonAlertBoxClosed|CookieConsent|didomi_token|CONSENT|SOCS|euconsent|cmplz_|cookieyes-consent|borlabs-cookie|__lxG__consent__|cookie_notice_accepted/i.test(document.cookie)) {
        return true;
      }
      for (const key of Object.keys(localStorage)) {
        if (/consent|ot_user_id|OTConsent|cky-consent|didomi|cmplz|borlabs|euconsent|__lxG/i.test(key)) return true;
      }
    } catch (e) {}
    return false;
  }

  function isVisible(el) {
    const style = getComputedStyle(el);
    if (style.display === "none" || style.visibility === "hidden" || style.opacity === "0") return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }

  function findAcceptButton() {
    const selectors = [
      "#onetrust-accept-btn-handler",
      "#CybotCookiebotDialogBodyLevelButtonLevelOptinAllowAll",
      "#didomi-notice-agree-button",
      ".cmplz-accept",
      ".cky-btn-accept",
      "button[aria-label*='accept' i]",
      "button[aria-label*='agree' i]",
      "button[aria-label*='allow' i]",
    ];
    for (const sel of selectors) {
      try {
        const el = document.querySelector(sel);
        if (el && isVisible(el)) return el;
      } catch (e) {}
    }
    const verbs = /accept all|accept cookies|accept all cookies|allow all|accept & continue|accept and continue|agree|got it|i agree|consent/i;
    // Note: deliberately exclude bare <a> tags — a stray "Accept" link would
    // navigate away instead of dismissing a banner.
    for (const el of document.querySelectorAll("button, [role='button']")) {
      if (!isVisible(el)) continue;
      const t = (el.textContent || "").trim();
      if (t && t.length <= 40 && verbs.test(t)) return el;
    }
    return null;
  }

  async function handleConsent() {
    // A currently visible banner wins even when a stored consent decision exists —
    // re-consent banners can appear despite old consent state. Stored consent is
    // only the fast path when no banner is actually showing.
    const btn = findAcceptButton();
    if (btn) {
      try {
        btn.click();
        await sleep(1_000);   // let the banner dismiss
        return "accepted";
      } catch (e) {
        return "click_failed";
      }
    }
    if (detectStoredConsent()) return "stored";
    return "none";
  }

  // ── Challenge classification ──
  function detectConsent() {
    return location.hostname === "consent.google.com";
  }

  function detectHardChallenge() {
    const title = document.title || "";
    if (/prove you('re| are) (a )?(human|not a robot)|verify you are human|i'?m not a robot|attention required|enable javascript/i.test(title)) {
      return "title: " + title.slice(0, 80);
    }
    if (document.querySelector("iframe[src*='recaptcha'], iframe[src*='hcaptcha'], iframe[src*='captcha']")) {
      return "captcha iframe";
    }
    if (location.pathname.startsWith("/sorry/")) return "google /sorry/ block";
    const bodyText = (document.body && document.body.innerText || "").slice(0, 2000);
    if (/prove you('re| are) (a )?(human|not a robot)|verify you are human/i.test(bodyText)) {
      return "body challenge text";
    }
    return null;
  }

  // DOM/text evidence that we're still ON an auto-resolving challenge screen
  // (Cloudflare "checking your browser" etc.). Distinct from detectHardChallenge,
  // which is the "needs a human" decision.
  function detectChallengeText() {
    const title = document.title || "";
    if (/just a moment|checking your browser|unusual (traffic|activity)/i.test(title)) {
      return "title: " + title.slice(0, 80);
    }
    const bodyText = (document.body && document.body.innerText || "").slice(0, 2000);
    if (/just a moment|checking your browser|unusual (traffic|activity)/i.test(bodyText)) {
      return "body challenge text";
    }
    return null;
  }

  // Are we STILL on a challenge page? cf-mitigated: challenge means "Cloudflare
  // definitely served a Challenge Page" — it does NOT say the challenge is
  // auto-resolving, and it goes stale if the challenge resolved in-place (no
  // redirect). So: DOM challenge text is the live truth; the header only counts
  // while genuine content hasn't appeared yet.
  function isStillChallenged(cfMitigated, extraction) {
    const dom = detectChallengeText();
    if (dom) return dom;
    if (cfMitigated === "challenge" && !isGenuineContent(extraction)) return "cf-mitigated: challenge";
    return null;
  }

  // Content quality gate: is the extracted candidate genuine article content
  // rather than a loading/placeholder/error/challenge page?
  function isGenuineContent(extraction) {
    const body = extraction.body || "";
    if (body.length < 400) return false;
    const title = (document.title || "").trim();
    if (!title) return false;
    if (/loading|please wait|just a moment|checking your browser|unusual traffic|not found|404|access denied|403|forbidden|attention required|captcha|prove you|enable javascript|error/i.test(title)) return false;
    const paragraphs = document.querySelectorAll("p").length;
    const headings = document.querySelectorAll("h1, h2, h3").length;
    return paragraphs >= 2 || headings >= 1;
  }

  // Poll the real extractor candidate. Early-exit when it is stable (unchanged
  // across two short samples) AND passes the quality gate; abort on terminal
  // blockers (consent wall, hard captcha). Keeps waiting through soft challenges
  // (which auto-resolve via redirect).
  async function extractUntilSettled(cfMitigated) {
    const SETTLE_MS = 2_000;
    const DEADLINE_MS = 20_000;
    const SAMPLE_MS = 500;
    const t0 = Date.now();

    await sleep(SETTLE_MS);

    let prev = extract();
    let candidate = prev;

    while (Date.now() - t0 < DEADLINE_MS) {
      if (detectConsent()) return { candidate, stable: false, terminal: "consent_required", reason: null, debug: { elapsed_ms: Date.now() - t0 } };
      const hard = detectHardChallenge();
      if (hard) return { candidate, stable: false, terminal: "challenge_required", reason: hard, debug: { elapsed_ms: Date.now() - t0 } };

      const curr = extract();
      candidate = curr;

      // Still on a challenge screen? Wait for it to resolve/redirect (a redirect
      // re-injects this script on the real page).
      if (isStillChallenged(cfMitigated, curr)) {
        prev = curr;
        await sleep(1_000);
        continue;
      }

      if (isGenuineContent(curr) && curr.body === prev.body) {
        return { candidate: curr, stable: true, terminal: null, reason: null, debug: { elapsed_ms: Date.now() - t0 } };
      }
      prev = curr;
      await sleep(SAMPLE_MS);
    }

    const challenged = isStillChallenged(cfMitigated, candidate);
    return { candidate, stable: false, terminal: challenged ? "challenge_required" : null, reason: challenged || null, debug: { elapsed_ms: Date.now() - t0, timed_out: true } };
  }

  // ═══════════════════════════════════════════════════
  // DOM extraction — heading-preserving text walk
  // ═══════════════════════════════════════════════════
  function selectContentRoot() {
    const selectors = ['article', 'main', '[role="main"]', '#content', '.content', '#main'];
    for (const sel of selectors) {
      const node = document.querySelector(sel);
      if (node) {
        const text = cleanText(node.textContent || '');
        if (text.length > 500) {
          return node;
        }
      }
    }
    return document.body;
  }

  function extract() {
    const sections = [];
    let currentHeading = "";
    let currentLevel = 0;
    let buffer = "";
    let fullBody = "";

    const SKIP_TAGS = new Set(["SCRIPT", "STYLE", "NAV", "NOSCRIPT", "IFRAME", "SVG", "TEMPLATE", "HEADER", "FOOTER", "ASIDE"]);
    const HEADING_TAGS = new Set(["H1", "H2", "H3", "H4", "H5", "H6"]);
    // Elements that close the current line: their text must not run into the
    // next block. Flushing here keeps prose, lists, and rows from collapsing
    // into one long run-on paragraph.
    const BLOCK_TAGS = new Set(["P", "DIV", "SECTION", "ARTICLE", "BLOCKQUOTE", "FIGURE", "FIGCAPTION", "LI", "UL", "OL", "DL", "DT", "DD", "PRE", "HR", "ADDRESS", "MAIN"]);

    const seenText = new Set();

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

    function appendText(raw) {
      const text = cleanText(raw);
      if (!text || seenText.has(text)) return;
      seenText.add(text);
      if (buffer && !buffer.endsWith("\n")) buffer += " ";
      buffer += text;
    }

    // Render a <table> as a GFM pipe table so it survives into the app's
    // markdown renderer. Cell text is cleaned and pipe characters escaped so a
    // stray "|" inside a cell can't break the table grid.
    function renderTable(tableEl) {
      const rows = [];
      for (const tr of tableEl.querySelectorAll("tr")) {
        const cells = [];
        for (const cell of tr.children) {
          if (cell.tagName === "TH" || cell.tagName === "TD") {
            cells.push(cleanText(cell.textContent).replace(/\|/g, "\\|"));
          }
        }
        if (cells.length) rows.push(cells);
      }
      if (!rows.length) return "";
      const width = Math.max(...rows.map(r => r.length));
      const pad = r => { const a = r.slice(); while (a.length < width) a.push(""); return a; };
      const header = pad(rows[0].slice());
      const lines = ["| " + header.join(" | ") + " |", "| " + header.map(() => "---").join(" | ") + " |"];
      for (let i = 1; i < rows.length; i++) {
        lines.push("| " + pad(rows[i].slice()).join(" | ") + " |");
      }
      return lines.join("\n");
    }

    function walk(node) {
      if (node.nodeType === Node.TEXT_NODE) {
        appendText(node.textContent);
        return;
      }
      if (node.nodeType !== Node.ELEMENT_NODE) return;
      const tag = node.tagName;
      if (!tag) return;
      if (SKIP_TAGS.has(tag)) return; // skip the whole subtree
      if (HEADING_TAGS.has(tag)) {
        flushSection();
        currentHeading = cleanText(node.textContent);
        currentLevel = parseInt(tag.charAt(1), 10);
        return; // heading text captured as the heading; don't re-walk it
      }
      if (tag === "TABLE") {
        flushSection();
        const md = renderTable(node);
        if (md) sections.push({ heading: currentHeading, heading_level: currentHeading ? currentLevel : 0, body: md });
        return; // skip subtree (cells already rendered)
      }
      if (tag === "BR") {
        buffer += "\n";
        return;
      }
      const isBlock = BLOCK_TAGS.has(tag);
      if (isBlock) flushSection();
      for (const child of node.childNodes) walk(child);
      if (isBlock) flushSection();
    }

    walk(selectContentRoot());
    flushSection();

    // Assemble full body from all sections.
    for (const sec of sections) {
      if (sec.heading) {
        fullBody += (fullBody ? "\n\n" : "") + "#".repeat(sec.heading_level) + " " + sec.heading + "\n\n" + sec.body;
      } else {
        fullBody += (fullBody ? "\n\n" : "") + sec.body;
      }
    }

    // If the walk produced nothing (SPA, shadow DOM), fall back to innerText.
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
