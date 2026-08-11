(async () => {
  const probe = await chrome.runtime.sendMessage({ type: "localsy_serp_probe" }).catch(() => null);
  if (!probe?.allowed) return;

  const pageUrl = location.href;

  if (location.hostname === "consent.google.com") {
    await report("consent_required", []);
    return;
  }

  if (location.pathname.startsWith("/sorry/") || /unusual traffic|not a robot/i.test(document.body?.innerText || "")) {
    await report("captcha", []);
    return;
  }

  if (location.pathname !== "/search") {
    await report("parse_failed", []);
    return;
  }

  const deadline = Date.now() + 8_000;
  let results = [];

  while (Date.now() < deadline) {
    results = extractOrganicResults();
    if (results.length > 0) break;
    await sleep(250);
  }

  await report(results.length ? "success" : "no_results", results);

  async function report(status, results) {
    await chrome.runtime.sendMessage({
      type: "localsy_serp_result",
      status,
      page_url: pageUrl,
      results
    }).catch(() => null);
  }

  function extractOrganicResults() {
    const root = document.querySelector("#search") || document.body;
    const anchors = [...root.querySelectorAll("a")];
    const seen = new Set();
    const output = [];

    for (const anchor of anchors) {
      const heading = anchor.querySelector("h3");
      if (!heading) continue;

      const url = normalizeResultUrl(anchor.href);
      if (!url || seen.has(url)) continue;

      const title = cleanText(heading.textContent);
      if (!title) continue;

      const container =
        anchor.closest("div.MjjYud") ||
        anchor.closest("div[data-snhf]") ||
        findUsefulAncestor(anchor);

      const snippet = extractSnippet(container, title);

      seen.add(url);
      output.push({
        position: output.length + 1,
        title,
        url,
        snippet
      });

      if (output.length >= 10) break;
    }

    return output;
  }

  function normalizeResultUrl(rawUrl) {
    try {
      const url = new URL(rawUrl);

      if (url.hostname.endsWith("google.com") && url.pathname === "/url") {
        const target = url.searchParams.get("q") || url.searchParams.get("url");
        return target ? normalizeResultUrl(target) : null;
      }

      if (url.protocol !== "http:" && url.protocol !== "https:") return null;
      if (url.hostname.endsWith("google.com")) return null;

      url.hash = "";
      return url.toString();
    } catch {
      return null;
    }
  }

  function findUsefulAncestor(anchor) {
    let node = anchor.parentElement;
    for (let i = 0; node && i < 6; i++, node = node.parentElement) {
      const text = cleanText(node.innerText);
      if (text.length >= 80) return node;
    }
    return anchor.parentElement;
  }

  function extractSnippet(container, title) {
    if (!container) return "";

    const preferred = container.querySelector(
      ".VwiC3b, .IsZvec, [data-sncf], [data-sncf='1'], [style*='-webkit-line-clamp']"
    );

    if (preferred) {
      const text = cleanText(preferred.innerText || preferred.textContent);
      if (text) return text.slice(0, 800);
    }

    const lines = (container.innerText || "")
      .split(/\n+/)
      .map(cleanText)
      .filter(Boolean)
      .filter((line) => line !== title)
      .filter((line) => !/^https?:\/\//i.test(line));

    return lines.join(" ").slice(0, 800);
  }

  function cleanText(value) {
    return String(value || "").replace(/\s+/g, " ").trim();
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }
})();
