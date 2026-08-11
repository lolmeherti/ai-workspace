const BRIDGE_URL = "ws://127.0.0.1:8765/";
const RECONNECT_ALARM = "localsy-search-bridge-reconnect";
const SEARCH_TIMEOUT_MS = 12_000;
const FETCH_NAV_TIMEOUT_MS = 12_000;
const FETCH_HUMAN_TIMEOUT_MS = 75_000;
const CAPTCHA_POLL_MS = 1_000;

let socket = null;
let reconnectTimer = null;
let heartbeatTimer = null;
let activeJob = null;

function send(message) {
  if (!socket || socket.readyState !== WebSocket.OPEN) return false;
  socket.send(JSON.stringify(message));
  return true;
}

function scheduleReconnect(delayMs = 2_000) {
  if (reconnectTimer) return;
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    connect();
  }, delayMs);
}

function startHeartbeat() {
  stopHeartbeat();
  heartbeatTimer = setInterval(() => {
    send({ type: "ping", ts: Date.now() });
  }, 20_000);
}

function stopHeartbeat() {
  if (heartbeatTimer) clearInterval(heartbeatTimer);
  heartbeatTimer = null;
}

function connect() {
  if (socket && (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING)) {
    return;
  }

  try {
    socket = new WebSocket(BRIDGE_URL);
  } catch {
    scheduleReconnect();
    return;
  }

  socket.onopen = () => {
    send({
      type: "hello",
      bridge_version: "0.1.0",
      user_agent: navigator.userAgent
    });
    startHeartbeat();
  };

  socket.onmessage = async (event) => {
    let message;
    try {
      message = JSON.parse(event.data);
    } catch {
      send({ type: "error", error: "invalid_json" });
      return;
    }

    if (message.action === "search") {
      await startSearch(message);
    } else if (message.action === "fetch") {
      await startFetch(message);
    }
  };

  socket.onclose = () => {
    stopHeartbeat();
    socket = null;
    scheduleReconnect();
  };

  socket.onerror = () => {
    // onclose handles reconnect.
  };
}

// ════════════════════════════════════════════════════════════════
// SERP search
// ════════════════════════════════════════════════════════════════

async function startSearch(message) {
  const requestId = String(message.request_id || "");
  const query = String(message.query || "").trim();

  if (!requestId || !query) {
    send({ type: "search_result", request_id: requestId || null, status: "invalid_request", results: [] });
    return;
  }

  if (activeJob) {
    send({ type: "search_result", request_id: requestId, status: "busy", results: [] });
    return;
  }

  const url = `https://www.google.com/search?q=${encodeURIComponent(query)}&num=10`;

  const tab = await chrome.tabs.create({ url: "about:blank", active: false });

  activeJob = {
    jobType: "search",
    requestId,
    query,
    tabId: tab.id,
    timeout: setTimeout(() => finishJob("timeout", null), SEARCH_TIMEOUT_MS)
  };

  await chrome.tabs.update(tab.id, { url, active: false });
}

// ════════════════════════════════════════════════════════════════
// Page fetch
// ════════════════════════════════════════════════════════════════

async function startFetch(message) {
  const requestId = String(message.request_id || "");
  const url = String(message.url || "").trim();

  if (!requestId || !url) {
    send({ type: "fetch_result", request_id: requestId || null, status: "invalid_request", content: null });
    return;
  }

  if (activeJob) {
    send({ type: "fetch_result", request_id: requestId, status: "busy", content: null });
    return;
  }

  const tab = await chrome.tabs.create({ url: "about:blank", active: false });

  activeJob = {
    jobType: "fetch",
    requestId,
    url,
    tabId: tab.id,
    navTimeout: setTimeout(() => finishJob("timeout", null), FETCH_NAV_TIMEOUT_MS),
    humanDeadline: null,
    pollTimer: null
  };

  await chrome.tabs.update(tab.id, { url, active: false });
}

// ════════════════════════════════════════════════════════════════
// CAPTCHA polling
// ════════════════════════════════════════════════════════════════

function startCaptchaPolling() {
  const job = activeJob;
  if (!job || job.jobType !== "fetch") return;

  // Activate the tab so the user can see and solve the CAPTCHA.
  chrome.tabs.update(job.tabId, { active: true }).catch(() => {});

  // Replace the 12s nav timeout with a 75s human-interaction window.
  clearTimeout(job.navTimeout);
  job.humanDeadline = Date.now() + FETCH_HUMAN_TIMEOUT_MS;

  function poll() {
    if (!activeJob || activeJob !== job) return;

    if (Date.now() >= job.humanDeadline) {
      finishJob("timeout", null);
      return;
    }

    chrome.tabs.sendMessage(job.tabId, { type: "localsy_fetch_poll" }).catch(() => {});

    job.pollTimer = setTimeout(poll, CAPTCHA_POLL_MS);
  }

  poll();
}

function stopCaptchaPolling() {
  const job = activeJob;
  if (!job) return;
  if (job.pollTimer) {
    clearTimeout(job.pollTimer);
    job.pollTimer = null;
  }
}

// ════════════════════════════════════════════════════════════════
// Job completion
// ════════════════════════════════════════════════════════════════

async function finishJob(status, content) {
  const job = activeJob;
  if (!job) return;

  clearTimeout(job.navTimeout);
  stopCaptchaPolling();
  activeJob = null;

  // Close tab on all terminal statuses. No tab activation for any status.
  try { await chrome.tabs.remove(job.tabId); } catch {}

  if (job.jobType === "search") {
    send({
      type: "search_result",
      request_id: job.requestId,
      query: job.query,
      status,
      results: Array.isArray(content) ? content : []
    });
  } else if (job.jobType === "fetch") {
    send({
      type: "fetch_result",
      request_id: job.requestId,
      status,
      content: content || null
    });
  }
}

// ════════════════════════════════════════════════════════════════
// Message routing
// ════════════════════════════════════════════════════════════════

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  // --- SERP probe ---
  if (message?.type === "localsy_serp_probe") {
    const allowed = Boolean(activeJob && sender.tab?.id === activeJob.tabId && activeJob.jobType === "search");
    sendResponse({ allowed });
    return;
  }

  // --- SERP result ---
  if (message?.type === "localsy_serp_result") {
    if (!activeJob || activeJob.jobType !== "search" || sender.tab?.id !== activeJob.tabId) {
      sendResponse({ accepted: false });
      return;
    }

    finishJob(message.status, Array.isArray(message.results) ? message.results : []);
    sendResponse({ accepted: true });
    return;
  }

  // --- Fetch probe ---
  if (message?.type === "localsy_fetch_probe") {
    const allowed = Boolean(activeJob && sender.tab?.id === activeJob.tabId && activeJob.jobType === "fetch");
    sendResponse({ allowed });
    return;
  }

  // --- Fetch result ---
  if (message?.type === "localsy_fetch_result") {
    if (!activeJob || activeJob.jobType !== "fetch" || sender.tab?.id !== activeJob.tabId) {
      sendResponse({ accepted: false });
      return;
    }

    // Report all statuses immediately — don't poll for CAPTCHA.
    // The PHP pipeline is sequential and can't wait 75s for human input.
    // challenge_required/consent_required are terminal statuses: PHP will skip.
    finishJob(message.status, message.content || null);
    sendResponse({ accepted: true });
    return;
  }

  // --- Fetch poll response ---
  if (message?.type === "localsy_fetch_poll_result") {
    if (!activeJob || activeJob.jobType !== "fetch" || sender.tab?.id !== activeJob.tabId) {
      sendResponse({ accepted: false });
      return;
    }

    if (message.status === "success") {
      stopCaptchaPolling();
      finishJob("success", message.content || null);
    } else if (message.status === "resolved") {
      stopCaptchaPolling();
      chrome.tabs.update(job.tabId, { url: job.url, active: false }).catch(() => {});
      job.navTimeout = setTimeout(() => finishJob("timeout", null), FETCH_NAV_TIMEOUT_MS);
    }
    sendResponse({ accepted: true });
    return;
  }
});

// ════════════════════════════════════════════════════════════════
// Lifecycle
// ════════════════════════════════════════════════════════════════

chrome.runtime.onInstalled.addListener(() => connect());
chrome.runtime.onStartup.addListener(() => connect());
chrome.alarms.create(RECONNECT_ALARM, { periodInMinutes: 0.5 });
chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === RECONNECT_ALARM) connect();
});

connect();
