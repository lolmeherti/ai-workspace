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

  // ── YouTube acquisition probe ──
  // youtube-probe.js runs in the page's own world (hook before the player starts, then measure) and
  // cannot reach the relay from there; it reports by postMessage and this script carries the report out
  // inside its own fetch result. On a watch page the result waits for it, because the payload the
  // pipeline wants from a watch page is the measurement, not the player chrome the DOM walk returns.
  let probeReport = null;
  window.addEventListener("message", (event) => {
    const data = event.data;
    if (event.source !== window || !data) return;
    if (data.__localsy_probe === true) {
      probeReport = data.report || null;
      return;
    }
    // The probe's own requests that only the extension can make — a media URL resolved for the page is
    // cross-origin to it, so a page-context fetch of it answers to googlevideo's CORS headers, while the
    // extension's worker (host_permissions: <all_urls>) is not subject to them.
    const request = data.__localsy_probe_request;
    if (request && request.kind === "sw_fetch") {
      chrome.runtime.sendMessage({ type: "localsy_sw_fetch", url: request.url, range: request.range || null })
        .then((result) => {
          window.postMessage({ __localsy_probe_answer: { id: request.id, result: result || null } }, "*");
        })
        .catch((e) => {
          window.postMessage({ __localsy_probe_answer: { id: request.id, error: String((e && e.message) || e) } }, "*");
        });
    }
  });

  async function waitForYoutubeProbe() {
    if (!/(^|\.)youtube\.com$/i.test(location.hostname)) return null;
    if (!/^\/(watch|shorts)/.test(location.pathname)) return null;
    const deadline = Date.now() + 12_000;
    while (Date.now() < deadline) {
      if (probeReport) return probeReport;
      await sleep(500);
    }
    return probeReport || { _error: "youtube-probe reported nothing inside 12s" };
  }

  // A watch page is measured by the probe, not by the DOM walk. The text a watch page offers is player
  // chrome, and waiting for it to "settle" spends the whole fetch job's budget (and, before this, fed the
  // answer from the title and view count). So: report the probe's answer and skip the extraction.
  if (/(^|\.)youtube\.com$/i.test(location.hostname) && /^\/(watch|shorts)/.test(location.pathname)) {
    const probeAnswer = await waitForYoutubeProbe();
    await sendResult("success", {
      url: location.href,
      title: document.title,
      fetched_at: new Date().toISOString(),
      // The video's own words, when the page's player is the one that fetched the caption track: the
      // payload the watch pipeline wants from a watch page. It rides at a fixed place so the caller does
      // not have to know the probe's internals; `probe` keeps the whole measurement for diagnostics.
      captions: (probeAnswer && probeAnswer.caption_payload) || null,
      probe: probeAnswer,
      _debug: { probe_only: true }
    });
    return;
  }

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
  const probeReportForResult = await waitForYoutubeProbe();
  await sendResult("success", {
    url: location.href,
    title: document.title,
    fetched_at: new Date().toISOString(),
    probe: probeReportForResult,
    date_posted: extractJsonLdDatePosted(),
    dates: resolveSourceDates(),
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

  // A captcha widget only means "blocked" when the page has nothing to read.
  // Sites embed invisible captchas (reCAPTCHA Enterprise / hCaptcha) for their
  // own forms while serving normal content, so treating the mere presence of the
  // iframe as a wall discards a readable page. Content is the discriminator.
  // With no extraction available (early check) the iframe rule cannot judge and
  // stays silent; the settling loop re-checks it against a real candidate.
  function detectHardChallenge(extraction = null) {
    const title = document.title || "";
    if (/prove you('re| are) (a )?(human|not a robot)|verify you are human|i'?m not a robot|attention required|enable javascript/i.test(title)) {
      return "title: " + title.slice(0, 80);
    }
    const captchaFrame = document.querySelector("iframe[src*='recaptcha'], iframe[src*='hcaptcha'], iframe[src*='captcha']");
    if (captchaFrame && extraction && !isGenuineContent(extraction)) {
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

      const curr = extract();
      candidate = curr;

      const hard = detectHardChallenge(curr);
      if (hard) return { candidate, stable: false, terminal: "challenge_required", reason: hard, debug: { elapsed_ms: Date.now() - t0 } };

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

    // A text repeated verbatim is either boilerplate or the same block rendered a
    // second time (responsive layout, list + detail split view) — deduping that is
    // the point. But a SHORT repeated string is per-item data: every job card says
    // "Vollzeit", every card states its own date and salary. Deduping those across
    // the whole page stripped each item of its own attributes and left the single
    // surviving copy under the wrong item (measured: a card lost "vor 4 Tagen
    // veröffentlicht", "Wien", "Vollzeit" and its salary because an earlier card
    // carried the identical strings). Dedupe long text only; short strings stay
    // with the item that carries them.
    const DEDUPE_MIN_CHARS = 60;
    function appendText(raw) {
      const text = cleanText(raw);
      if (!text) return;
      if (text.length >= DEDUPE_MIN_CHARS) {
        if (seenText.has(text)) return;
        seenText.add(text);
      }
      if (buffer && !buffer.endsWith("\n")) buffer += " ";
      buffer += text;
    }

    // Render a table as a GFM pipe table so it survives into the app's markdown
    // renderer. Cell text is cleaned and pipe characters escaped so a stray "|"
    // inside a cell can't break the table grid.
    function renderTable(tableEl) {
      const rows = [];
      for (const tr of tableRows(tableEl)) {
        const cells = [];
        for (const cell of tableCells(tr)) {
          const text = cleanText(cell.textContent).replace(/\|/g, "\\|");
          if (text) cells.push(text);
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
      if (isTableEl(node)) {
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

  // ── Tables ──────────────────────────────────────────────────────────────────
  // Tables are increasingly built from divs carrying ARIA roles instead of
  // <table> (GitBook, Notion-likes, docs themes). Without this they never became
  // tables: their cells arrived as a run of loose values with the header row and
  // row boundaries gone.
  function isAriaTable(el) {
    if (!el || el.nodeType !== Node.ELEMENT_NODE) return false;
    const r = (el.getAttribute("role") || "").toLowerCase();
    return r === "table" || r === "grid";
  }

  function isTableEl(el) {
    return !!el && el.nodeType === Node.ELEMENT_NODE && (el.tagName === "TABLE" || isAriaTable(el));
  }

  function tableRows(el) {
    if (el.tagName === "TABLE") return [...el.querySelectorAll("tr")];
    const all = [...el.querySelectorAll('[role="row"]')];
    return all.filter(r => !all.some(o => o !== r && o.contains(r)));   // outermost rows
  }

  function tableCells(row) {
    if (row.tagName === "TR") {
      return [...row.children].filter(c => c.tagName === "TH" || c.tagName === "TD");
    }
    return [...row.children].filter(c => {
      const r = (c.getAttribute("role") || "").toLowerCase();
      return r === "cell" || r === "gridcell" || r === "columnheader" || r === "rowheader";
    });
  }

  // ── Source dates ────────────────────────────────────────────────────────────
  // Publication and update information usually sits in the material the article
  // extractor discards: a dateline above the body, a byline, or a footer line
  // ("This page was last edited on ..."). This pass reads the whole document for
  // it, keeps the sentence it came from so the KIND can be told apart, and
  // reports nothing rather than guessing. Explicit markup is preferred; plain
  // page text is the fallback, so a page that only writes the date in prose still
  // resolves. Deterministic: no model call.
  function resolveSourceDates() {
    const DATE_SHAPE = '(?:\\d{4}-\\d{2}-\\d{2}(?:[T ]\\d{2}:\\d{2}(?::\\d{2})?(?:Z|[+-]\\d{2}:?\\d{2})?)?'
    + '|\\d{1,2}\\.\\d{1,2}\\.\\d{4}'
    + '|\\d{1,2}\\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\\s+\\d{4}'
    + '|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\\s+\\d{1,2},\\s+\\d{4}'
    + '|\\d+\\s+(?:minute|hour|day|week|month|year)s?\\s+ago'
    + '|vor\\s+\\d+\\s+(?:Minuten?|Stunden?|Tagen?|Wochen?|Monaten?|Jahren?)'
    + '|\\d{1,2}:\\d{2}\\s*(?:AM|PM)?\\s*(?:GMT|UTC|CET|CEST|[+-]\\d{1,2})?)';
    const LABEL_PUB = /(published|posted|published on|edited by|ver(?:ö|o)ffentlicht|erschienen|stand)/i;
    const LABEL_UPD = /(last edited|last modified|last updated|updated|aktualisiert|zuletzt (?:ge(?:ä|a)ndert|bearbeitet))/i;

  // Text of an element with a separator between adjacent nodes: raw textContent
  // fuses inline elements ("September 30, 20269:01 PM").
  function spacedText(el) {
    if (!el) return '';
    if (el.nodeType === Node.TEXT_NODE) return el.textContent || '';
    let out = '';
    for (const child of el.childNodes) {
      if (child.nodeType === Node.ELEMENT_NODE) out += spacedText(child) + ' ';
      else out += (child.nodeValue || '') + ' ';
    }
    return out.replace(/\s+/g, ' ');
  }



    const out = { published: null, updated: null, published_evidence: null, updated_evidence: null,
                  published_signal: null, updated_signal: null, candidates: 0 };
    try {
      const meta = { publication: [], update: [] };
      // explicit metadata first
      for (const node of document.querySelectorAll('meta[property],meta[name],meta[itemprop]')) {
        const key = (node.getAttribute('property') || node.getAttribute('name') || node.getAttribute('itemprop') || '').toLowerCase();
        const val = (node.getAttribute('content') || '').trim();
        if (!val) continue;
        if (/^(article:published_time|og:published_time|datepublished|pubdate|dc\.date|date)$/.test(key)) meta.publication.push({ key, val });
        else if (/^(article:modified_time|og:updated_time|datemodified|last-modified)$/.test(key)) meta.update.push({ key, val });
      }
      for (const node of document.querySelectorAll('script[type="application/ld+json"]')) {
        let data; try { data = JSON.parse(node.textContent || ''); } catch (e) { continue; }
        const walkJson = (o) => {
          if (o == null) return;
          if (Array.isArray(o)) { o.forEach(walkJson); return; }
          if (typeof o !== 'object') return;
          for (const [k, v] of Object.entries(o)) {
            const key = k.toLowerCase();
            if (typeof v === 'string' && v.trim()) {
              if (key === 'datepublished') meta.publication.push({ key: 'json-ld datePublished', val: v.trim() });
              if (key === 'dateposted') meta.publication.push({ key: 'json-ld datePosted', val: v.trim() });
              if (key === 'datemodified') meta.update.push({ key: 'json-ld dateModified', val: v.trim() });
            } else walkJson(v);
          }
        };
        walkJson(data);
      }
      // visible marked-up dates: <time datetime>
      const timeEls = [...document.querySelectorAll('time[datetime]')];
      // plain text in the regions the article extractor throws away
      const candidates = [];
      const push = (region, text) => {
        let text2 = String(text || '');
        if (!text2.trim()) return;
        // every date in the fragment, not only the first
        const re = new RegExp('([^.!?|]{0,110}?(' + DATE_SHAPE + ')[^.!?|]{0,50})', 'gi');
        let m; let guard = 0;
        while ((m = re.exec(text2)) !== null && guard++ < 40) {
          const sentence = m[1].replace(/\s+/g, ' ').trim();
          const kind = LABEL_UPD.test(sentence) ? 'update' : (LABEL_PUB.test(sentence) ? 'publication' : 'unknown');
          candidates.push({ region, sentence, date: m[2].trim(), kind });
        }
      };
      for (const tag of ['header', 'footer', 'aside', 'nav']) {
        for (const el of document.querySelectorAll(tag)) push(tag, spacedText(el));
      }
      const h1 = document.querySelector('h1');
      if (h1 && h1.parentElement) {
        const before = [], after = [];
        let seen = false;
        for (const child of h1.parentElement.childNodes) {
          if (child === h1) { seen = true; continue; }
          if (child.nodeType === Node.ELEMENT_NODE) (seen ? after : before).push(spacedText(child));
          else (seen ? after : before).push(child.nodeValue || '');
        }
        if (before.length) push('byline-before-headline', before.join(' ').slice(-1200));
        if (after.length) push('byline-after-headline', after.join(' ').slice(0, 700));
      }
      out.candidates = candidates.length;

      const pick = (kind) => {
        if (kind === 'publication' && meta.publication.length) {
          return { date: meta.publication[0].val, evidence: meta.publication[0].key + ' = ' + meta.publication[0].val, signal: 'metadata' };
        }
        if (kind === 'update' && meta.update.length) {
          return { date: meta.update[0].val, evidence: meta.update[0].key + ' = ' + meta.update[0].val, signal: 'metadata' };
        }
        if (kind === 'publication') {
          // a date standing before an explicit "Updated" in the same fragment is the
          // publication date (the usual dateline shape)
          for (const c of candidates) {
            if (LABEL_UPD.test(c.sentence)) {
              const m = c.sentence.match(new RegExp('(' + DATE_SHAPE + ')[^.!?]{0,60}?(?:updated|last modified|last updated|aktualisiert)', 'i'));
              if (m) return { date: m[1].trim(), evidence: c.sentence, signal: 'dateline-pattern' };
            }
          }
        }
        const wantTime = kind === 'publication' ? /published/i : /modifi|updated/i;
        if (timeEls.length) {
          for (const el of timeEls) {
            if (kind === 'update' && !wantTime.test(el.outerHTML || '')) continue;
            return { date: (el.getAttribute('datetime') || '').trim(), evidence: spacedText(el).trim() || '<time datetime>', signal: 'time-element' };
          }
        }
        for (const c of candidates) {
          if (c.kind === kind) return { date: c.date, evidence: c.sentence, signal: 'labelled-text' };
        }
        return null;
      };
      const pub = pick('publication');
      const upd = pick('update');
      if (pub) { out.published = pub.date; out.published_evidence = pub.evidence; out.published_signal = pub.signal; }
      if (upd) { out.updated = upd.date; out.updated_evidence = upd.evidence; out.updated_signal = upd.signal; }
    } catch (e) {
      // Never fail silently: an error here used to ship an empty dates object that
      // looked exactly like "the page states no date".
      out.error = String((e && e.message) || e);
    }
    return out;
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
})().catch(err => {
  chrome.runtime.sendMessage({
    type: "localsy_fetch_result",
    status: "extract_error",
    content: {
      _error: String((err && err.message) || err),
      _stack: String((err && err.stack) || "").slice(0, 800),
      _debug: { url: location.href, readyState: document.readyState, title: document.title }
    }
  }).catch(() => {});
});

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
