// Localsy caption probe — MAIN world, injected at document_start on YouTube pages.
//
// One question: when the PLAYER itself asks for the subtitle track, do the caption bytes come back?
// Two attempts already failed — fetching the track's URL directly from the extension returned 200 with an
// empty body, and the app's own caption fetch during a real watch returned 0 segments while the audio was
// transcribed into 131 — so this asks the one remaining way: switch captions ON in a tab and read the
// request the player makes for itself.
//
// It does not play, seek, unmute or focus anything; a background tab is the whole of it. It reports by
// postMessage and generic-extractor.js carries the report out inside its own fetch result.

(function () {
  "use strict";

  const REPORT_AT_MS = 9000;
  const BACKSTOP_MS = 11000;
  const t0 = Date.now();

  const report = {
    started_at: new Date().toISOString(),
    url: location.href,
    hooks: { fetch: false, xhr: false },
    log: [],
    player_response: null,
    caption_tracks: null,
    cc: { button_found: false, button_clicked: false, api_calls: [], active_after: null },
    caption_requests: [],
    caption_payload: null,
    rendered_sample: [],
    base_url_fetch: null,
    elapsed_ms: null
  };

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const paramsOf = (u) => { try { return [...new URL(u).searchParams.keys()]; } catch (e) { return []; } };
  const hostOf = (u) => { try { return new URL(u).host; } catch (e) { return null; } };

  function log(msg, extra) {
    report.log.push({ t: Date.now() - t0, msg: msg, extra: extra === undefined ? null : extra });
  }

  let selfRequests = 0;
  function selfFetch(url, init) {
    selfRequests++;
    try { return fetch(url, init); } finally { selfRequests--; }
  }

  const isCaptionUrl = (u) => /timedtext|caption/i.test(String(u));

  const CAPTION_PAYLOAD_MAX = 2_000_000;

  function noteCaptionRequest(url, method, status, ct, bytes, head, fullText) {
    report.caption_requests.push({
      at_ms: Date.now() - t0, host: hostOf(url), path_kind: /timedtext/i.test(String(url)) ? "timedtext" : "other",
      method: method, status: status, ct: ct, bytes: bytes, params: paramsOf(url).slice(0, 16),
      head: (head || "").slice(0, 400)
    });
    log("caption request seen", { status: status, ct: ct, bytes: bytes });
    // The whole track, when the player is the one that fetched it: this payload IS the video's words, and
    // carrying it out of the page is the point of the exercise.
    if (status === 200 && typeof fullText === "string" && fullText.length > 0 && fullText.length <= CAPTION_PAYLOAD_MAX) {
      report.caption_payload = {
        ct: ct, bytes: fullText.length, params: paramsOf(url).slice(0, 16), text: fullText
      };
      log("caption payload captured", { bytes: fullText.length, ct: ct });
    }
  }

  // ── fetch hook ───────────────────────────────────────────────────────────────
  try {
    const originalFetch = window.fetch;
    window.fetch = function (input, init) {
      const url = typeof input === "string" ? input : ((input && input.url) || "");
      const method = String((init && init.method) || (input && input.method) || "GET").toUpperCase();
      const isSelf = selfRequests > 0;
      const p = originalFetch.apply(this, arguments);
      if (!isSelf && isCaptionUrl(url)) {
        log("fetch to a caption URL", { host: hostOf(url), params: paramsOf(url).slice(0, 10) });
        p.then((r) => {
          void r.clone().text().then((text) => {
            noteCaptionRequest(url, method, r.status, r.headers.get("content-type"), text.length, text, text);
          }).catch((e) => { log("caption response unreadable", String((e && e.message) || e)); });
        }, () => {});
      }
      return p;
    };
    report.hooks.fetch = true;
    log("fetch hook installed");
  } catch (e) { log("fetch hook FAILED", String((e && e.message) || e)); }

  // ── XHR hook ─────────────────────────────────────────────────────────────────
  try {
    const open = XMLHttpRequest.prototype.open;
    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
      try { this.__localsy = { method: String(method || "GET").toUpperCase(), url: String(url || "") }; } catch (e) {}
      return open.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
      const meta = this.__localsy;
      if (meta && isCaptionUrl(meta.url)) {
        log("XHR to a caption URL", { host: hostOf(meta.url), params: paramsOf(meta.url).slice(0, 10) });
        this.addEventListener("loadend", () => {
          try {
            const text = (this.responseType === "" || this.responseType === "text") ? String(this.responseText || "") : "";
            noteCaptionRequest(meta.url, meta.method, this.status, this.getResponseHeader("content-type") || "", text.length, text, text);
          } catch (e) {}
        });
      }
      return send.apply(this, arguments);
    };
    report.hooks.xhr = true;
    log("xhr hook installed");
  } catch (e) { log("xhr hook FAILED", String((e && e.message) || e)); }

  // ── the caption track the page itself was offered ────────────────────────────
  function captionTracks() {
    const pr = window.ytInitialPlayerResponse || {};
    const tl = (pr.captions && pr.captions.playerCaptionsTracklistRenderer) || {};
    return tl.captionTracks || [];
  }

  function describeTracks() {
    const tracks = captionTracks();
    const chosen = tracks.find((t) => (t.languageCode || "").startsWith("en")) || tracks[0] || null;
    report.caption_tracks = {
      count: tracks.length,
      kinds: tracks.slice(0, 6).map((t) => t.languageCode + "/" + (t.kind || "manual")),
      chosen: chosen ? { languageCode: chosen.languageCode, kind: chosen.kind || "manual", has_url: !!chosen.baseUrl, params: paramsOf(chosen.baseUrl).slice(0, 14) } : null
    };
  }

  // ── switch captions on, the way the player's own UI would ────────────────────
  // No track is forced: the player picks its own default, which is what a human sees when they press CC —
  // the video's own language, human track before automatic, never a translation. Forcing a language here
  // would hand a German video an English translation of itself.
  function enableCaptions() {
    const player = document.getElementById("movie_player");
    const button = document.querySelector(".ytp-subtitles-button");
    report.cc.button_found = !!button;

    if (player && typeof player.setOption === "function") {
      try {
        if (typeof player.loadModule === "function") {
          player.loadModule("captions");
          report.cc.api_calls.push("loadModule(captions)");
        }
        player.setOption("captions", "reload", true);
        report.cc.api_calls.push("setOption(captions,reload)");
      } catch (e) {
        report.cc.api_calls.push("api error: " + String((e && e.message) || e));
      }
    } else {
      report.cc.api_calls.push("no movie_player / setOption");
    }

    if (button && button.getAttribute("aria-pressed") !== "true") {
      try { button.click(); report.cc.button_clicked = true; } catch (e) { report.cc.api_calls.push("click failed: " + String((e && e.message) || e)); }
    }
    log("captions switched on", report.cc);
  }

  function readCcState() {
    const player = document.getElementById("movie_player");
    try {
      if (player && typeof player.getOption === "function") {
        report.cc.active_after = {
          track: player.getOption("captions", "track") || null,
          on: player.getOption("captions", "tracklist") ? true : null
        };
      }
    } catch (e) { report.cc.active_after = { error: String((e && e.message) || e) }; }
    const button = document.querySelector(".ytp-subtitles-button");
    if (button) report.cc.button_pressed_after = button.getAttribute("aria-pressed");
    report.rendered_sample = [...document.querySelectorAll(".ytp-caption-segment")].slice(0, 5).map((n) => (n.textContent || "").trim());
  }

  // The track URL fetched by us, for comparison with whatever the player got.
  async function probeBaseUrlDirect() {
    const tracks = captionTracks();
    const chosen = tracks.find((t) => (t.languageCode || "").startsWith("en")) || tracks[0];
    if (!chosen || !chosen.baseUrl) { report.base_url_fetch = { skipped: "no track url" }; return; }
    try {
      const r = await selfFetch(chosen.baseUrl + "&fmt=json3", { credentials: "include" });
      const text = await r.text();
      report.base_url_fetch = { status: r.status, ct: r.headers.get("content-type"), bytes: text.length, head: text.slice(0, 200).replace(/\s+/g, " ") };
    } catch (e) {
      report.base_url_fetch = { error: String((e && e.message) || e) };
    }
    log("direct caption fetch", report.base_url_fetch);
  }

  function finalize() {
    if (report.__done) return;
    report.__done = true;
    readCcState();
    report.elapsed_ms = Date.now() - t0;
    log("finalize", { caption_requests: report.caption_requests.length, elapsed_ms: report.elapsed_ms });
    try { window.postMessage({ __localsy_probe: true, report: report }, "*"); } catch (e) {}
  }

  (async function run() {
    setTimeout(() => { log("backstop fired"); finalize(); }, BACKSTOP_MS);
    log("caption probe started", { visibility: document.visibilityState });
    await sleep(2500);
    const pr = window.ytInitialPlayerResponse || {};
    const af = ((pr.streamingData || {}).adaptiveFormats) || [];
    report.player_response = {
      playability: (pr.playabilityStatus && pr.playabilityStatus.status) || null,
      streaming_keys: Object.keys(pr.streamingData || {}),
      audio: af.filter((f) => String(f.mimeType || "").startsWith("audio/")).length,
      audio_with_url: af.filter((f) => String(f.mimeType || "").startsWith("audio/") && f.url).length
    };
    describeTracks();
    log("tracks read", report.caption_tracks);
    if (!document.querySelector("video")) log("no video element yet");
    enableCaptions();
    await sleep(3500);
    readCcState();
    log("cc state after enabling", { active_after: report.cc.active_after, rendered: report.rendered_sample.slice(0, 2) });
    const remaining = REPORT_AT_MS - (Date.now() - t0);
    if (remaining > 0) await sleep(remaining);
    await probeBaseUrlDirect();
    finalize();
  })().catch((err) => {
    report.error = String((err && err.message) || err);
    log("run threw", report.error);
    finalize();
  });
})();
