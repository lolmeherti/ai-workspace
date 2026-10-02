// Localsy extraction-compare harness — service worker.
//
// Standalone. It does NOT talk to the Go relay and does NOT touch the production
// bridge. It polls a local job server (server.js) started by compare.php, and for
// each capture job:
//   1. opens the URL in one inactive tab of the browser it runs in,
//   2. answers the production extractor's authorization probe as "allowed" so the
//      UNMODIFIED deterministic extractor (deterministic-extractor.js) runs and
//      reports its result exactly as it does in production,
//   3. once the extractor settles, asks capture-dom.js for the compact DOM map,
//      the full content store and the serialized DOM,
//   4. posts everything to the job server and closes the tab.

const JOB_SERVER = "http://127.0.0.1:8790";
const POLL_MS = 1_000;
const CAPTURE_DEADLINE_MS = 120_000;

let activeJob = null;
let lastPollAt = 0;

function log(...args) {
  console.log("[compare-harness]", ...args);
}

async function post(path, body) {
  try {
    const res = await fetch(JOB_SERVER + path, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    });
    return res.ok;
  } catch (e) {
    log("post failed", path, String(e && e.message || e));
    return false;
  }
}

async function progress(stage, detail) {
  await post("/progress", {
    snapshot_id: activeJob ? activeJob.snapshotId : null,
    stage,
    detail: detail || null,
    ts: Date.now()
  });
}

async function pollOnce() {
  lastPollAt = Date.now();
  if (activeJob) return;
  try {
    const res = await fetch(JOB_SERVER + "/job", { cache: "no-store" });
    if (res.status === 200) {
      const job = await res.json();
      if (job && job.type === "capture" && job.url) {
        startCapture(job).catch((e) => log("capture threw", String(e && e.message || e)));
      }
    }
  } catch (e) {
    // server not running — stay quiet, retry
  }
}

let loopRunning = false;
function ensureLoop() {
  if (loopRunning) return;
  loopRunning = true;
  const tick = async () => {
    await pollOnce();
    setTimeout(tick, POLL_MS);
  };
  tick();
}

async function startCapture(job) {
  const snapshotId = String(job.snapshot_id);
  const startedAt = Date.now();

  const tab = await chrome.tabs.create({ url: "about:blank", active: false });

  activeJob = {
    snapshotId,
    url: String(job.url),
    options: job.options || null,
    tabId: tab.id,
    startedAt,
    extractorResult: null,
    deadline: setTimeout(() => {
      finishCapture("deadline", { error: "harness capture deadline reached" });
    }, CAPTURE_DEADLINE_MS)
  };

  progress("navigating", { url: activeJob.url });
  await chrome.tabs.update(tab.id, { url: activeJob.url, active: false });
}

async function finishCapture(failureReason, extra) {
  const job = activeJob;
  if (!job) return;
  activeJob = null;
  clearTimeout(job.deadline);

  const payload = {
    snapshot_id: job.snapshotId,
    url: job.url,
    ok: !failureReason && !!job.extractorResult,
    failure: failureReason || null,
    extractor: job.extractorResult,
    map: job.mapPayload ? job.mapPayload.map : null,
    store: job.mapPayload ? job.mapPayload.store : null,
    limitations: job.mapPayload ? job.mapPayload.limitations : null,
    capture_timings: {
      nav_and_extract_ms: job.extractorResult ? job.extractorResult.ms : null,
      map_build_ms: job.mapPayload ? job.mapPayload.timings.map_build_ms : null,
      dom_serialize_ms: job.mapPayload ? job.mapPayload.timings.dom_serialize_ms : null,
      total_ms: Date.now() - job.startedAt
    },
    dom_html: job.domHtml || null,
    dom_html_truncated: !!job.domHtmlTruncated,
    notes: extra || null,
    harness_version: chrome.runtime.getManifest().version
  };

  progress("posting_result", { ok: payload.ok });
  await post("/result", payload);
  try { await chrome.tabs.remove(job.tabId); } catch (e) {}
}

async function buildMap() {
  const job = activeJob;
  if (!job) return;
  progress("building_map");
  let response = null;
  try {
    response = await chrome.tabs.sendMessage(job.tabId, { type: "localsy_capture_build", options: job.options });
  } catch (e) {
    await finishCapture("map_message_failed", { error: String(e && e.message || e) });
    return;
  }
  if (!response || !response.ok) {
    await finishCapture("map_build_failed", { error: response ? response.error : "no response" });
    return;
  }
  job.mapPayload = response.payload;
  job.domHtml = response.payload.dom_html;
  job.domHtmlTruncated = response.payload.dom_html_truncated;
  await finishCapture(null);
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  // Authorization probe from the unmodified deterministic extractor.
  if (message?.type === "localsy_fetch_probe") {
    const allowed = Boolean(
      activeJob && sender.tab?.id === activeJob.tabId
    );
    sendResponse({ allowed, cf_mitigated: null });
    return;
  }

  // The extractor's result — Pipeline A, produced by production code, verbatim.
  if (message?.type === "localsy_fetch_result") {
    if (!activeJob || sender.tab?.id !== activeJob.tabId) {
      sendResponse({ accepted: false });
      return;
    }
    const content = message.content || null;
    let bodyLen = 0;
    let entityCount = 0;
    if (content && Array.isArray(content.entities)) {
      entityCount = content.entities.length;
      for (const e of content.entities) bodyLen += String(e.body || "").length;
    }
    activeJob.extractorResult = {
      status: message.status,
      ms: Date.now() - activeJob.startedAt,
      entity_count: entityCount,
      body_chars: bodyLen,
      content
    };
    progress("extractor_result", { status: message.status, entities: entityCount, body_chars: bodyLen });
    // Pipeline A is settled. Same page instance, same moment: capture the map.
    buildMap();
    sendResponse({ accepted: true });
    return;
  }

  // Poll messages would belong to the production CAPTCHA flow; not used here.
  if (message?.type === "localsy_fetch_poll") {
    sendResponse({ accepted: false });
    return;
  }
});

// MV3 suspends an idle service worker, which kills a bare setTimeout chain — the
// production extension survives this with a chrome.alarms keep-alive, and so does
// this harness (that gap cost us the connection after the first captures).
const POLL_ALARM = "localsy-compare-poll";
chrome.alarms.create(POLL_ALARM, { periodInMinutes: 0.5 });
chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name !== POLL_ALARM) return;
  // Force a fresh loop: if the worker was suspended, the old chain is gone and
  // loopRunning is stale. Re-dispatch is impossible — the server hands out one
  // job at a time and answers 204 while a job is current.
  loopRunning = false;
  ensureLoop();
});

chrome.runtime.onInstalled.addListener(() => ensureLoop());
chrome.runtime.onStartup.addListener(() => ensureLoop());
ensureLoop();
