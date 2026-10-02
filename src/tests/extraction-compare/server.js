#!/usr/bin/env node
// Localsy extraction-compare harness — capture job server (host side).
//
// Runs on the HOST (Node), because it owns the browser. Two channels:
//   • browser  <-> server : HTTP on 127.0.0.1:<port>  (the harness extension polls /job)
//   • PHP      <-> server : the shared run directory  (compare.php drops
//                           runs/<id>/request.json; this server picks it up and
//                           writes the capture artifacts next to it)
// No port is exposed beyond 127.0.0.1 by default; PHP never talks HTTP to this
// process, so the Docker container only needs the bind-mounted ./src tree.
//
// Standalone: it does not touch the production bridge, relay, or extension.

const http = require("http");
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const HERE = __dirname;
const REPO = path.resolve(HERE, "..", "..", "..");
const RUNS_DIR = path.join(HERE, "runs");
const HARNESS_DIR = path.join(HERE, "harness");
const PRODUCTION_EXTRACTOR = path.join(REPO, "localsy-search-bridge", "generic-extractor.js");
const HARNESS_EXTRACTOR = path.join(HARNESS_DIR, "deterministic-extractor.js");

const argv = process.argv.slice(2);
const arg = (name, fallback) => {
  const hit = argv.find((a) => a === `--${name}` || a.startsWith(`--${name}=`));
  if (!hit) return fallback;
  const eq = hit.indexOf("=");
  return eq === -1 ? true : hit.slice(eq + 1);
};

const PORT = parseInt(arg("port", "8790"), 10);
const BIND = String(arg("bind", "127.0.0.1"));
// No browser is ever launched by this server: the user's already-running browser,
// with the harness extension loaded, does the work by opening an unfocused tab —
// the same technique the production bridge uses.
const DOM_MAX_BYTES = parseInt(arg("dom-max-bytes", "8000000"), 10);
const POLL_MS = 750;
const STALE_MS = 6000;

const state = {
  queue: [],
  current: null,
  lastPollAt: 0,
  progress: [],
  startedAt: Date.now()
};

fs.mkdirSync(RUNS_DIR, { recursive: true });

function sha256(file) {
  try { return crypto.createHash("sha256").update(fs.readFileSync(file)).digest("hex"); }
  catch (e) { return null; }
}

const REDDIT_HARNESS = path.join(HARNESS_DIR, "reddit-extractor.js");
const REDDIT_PRODUCTION = path.join(REPO, "localsy-search-bridge", "reddit-extractor.js");

const extractorHashes = {
  harness_file: path.relative(REPO, HARNESS_EXTRACTOR).replace(/\\/g, "/"),
  production_file: path.relative(REPO, PRODUCTION_EXTRACTOR).replace(/\\/g, "/"),
  harness_sha256: sha256(HARNESS_EXTRACTOR),
  production_sha256: sha256(PRODUCTION_EXTRACTOR)
};
extractorHashes.identical_to_production =
  !!extractorHashes.harness_sha256 && extractorHashes.harness_sha256 === extractorHashes.production_sha256;
extractorHashes.reddit_adapter = {
  harness_file: path.relative(REPO, REDDIT_HARNESS).replace(/\\/g, "/"),
  production_file: path.relative(REPO, REDDIT_PRODUCTION).replace(/\\/g, "/"),
  harness_sha256: sha256(REDDIT_HARNESS),
  production_sha256: sha256(REDDIT_PRODUCTION),
  identical_to_production: sha256(REDDIT_HARNESS) === sha256(REDDIT_PRODUCTION)
};

function writeJson(file, obj) {
  fs.writeFileSync(file, JSON.stringify(obj, null, 2));
}

function newSnapshotId(url) {
  const ts = new Date().toISOString().replace(/[-:T]/g, "").slice(0, 15);
  const slug = crypto.createHash("sha1").update(url).digest("hex").slice(0, 8);
  return `${ts}-${slug}`;
}

// ── job watching (PHP -> server via the shared run directory) ───────────────
function claimJobs() {
  let entries = [];
  try { entries = fs.readdirSync(RUNS_DIR, { withFileTypes: true }); } catch (e) { return; }
  for (const entry of entries) {
    if (!entry.isDirectory() || entry.name.startsWith("_")) continue;
    const dir = path.join(RUNS_DIR, entry.name);
    const requestFile = path.join(dir, "request.json");
    const claimedFile = path.join(dir, "claimed.json");
    if (!fs.existsSync(requestFile) || fs.existsSync(claimedFile)) continue;
    let request;
    try { request = JSON.parse(fs.readFileSync(requestFile, "utf8")); } catch (e) { continue; }
    if (!request.url) continue;
    writeJson(claimedFile, { claimed_at: new Date().toISOString(), pid: process.pid });
    request.snapshot_id = entry.name;
    request.claimed_at = new Date().toISOString();
    state.queue.push(request);
    console.log(`[server] queued ${entry.name} → ${request.url}`);
  }
}

// ── harness extension endpoints ─────────────────────────────────────────────
function readBody(req) {
  return new Promise((resolve) => {
    let data = "";
    req.on("data", (chunk) => { data += chunk; if (data.length > 64 * 1024 * 1024) req.destroy(); });
    req.on("end", () => resolve(data));
  });
}

function send(res, code, body) {
  const payload = typeof body === "string" ? body : JSON.stringify(body);
  res.writeHead(code, {
    "Content-Type": "application/json",
    "Access-Control-Allow-Origin": "*",
    "Access-Control-Allow-Headers": "Content-Type"
  });
  res.end(payload);
}

function runDir(snapshotId) {
  return path.join(RUNS_DIR, snapshotId);
}

function storeResult(payload) {
  const id = payload.snapshot_id;
  const dir = runDir(id);
  fs.mkdirSync(dir, { recursive: true });

  const capturedAt = new Date().toISOString();
  const failure = payload.ok ? null : (payload.failure || "unknown");

  if (payload.dom_html) fs.writeFileSync(path.join(dir, "page.html"), payload.dom_html);
  if (payload.map) writeJson(path.join(dir, "dom-map.json"), payload.map);
  if (payload.store) writeJson(path.join(dir, "content-store.json"), payload.store);
  if (payload.limitations) writeJson(path.join(dir, "capture-limitations.json"), payload.limitations);
  if (payload.capture_timings) writeJson(path.join(dir, "capture-timings.json"), payload.capture_timings);
  if (payload.extractor) writeJson(path.join(dir, "pipeline-a.json"), payload.extractor);

  writeJson(path.join(dir, "capture-meta.json"), {
    snapshot_id: id,
    url: payload.url,
    ok: !!payload.ok,
    failure,
    captured_at: capturedAt,
    harness_version: payload.harness_version || null,
    dom_html_truncated: !!payload.dom_html_truncated,
    dom_html_bytes: payload.dom_html ? payload.dom_html.length : 0,
    extractor_source: extractorHashes,
    notes: payload.notes || null
  });

  const raw = { ...payload };
  delete raw.dom_html;
  writeJson(path.join(dir, "result.raw.json"), raw);

  if (!payload.ok) writeJson(path.join(dir, "capture-failed.json"), { failure, at: capturedAt });

  console.log(`[server] stored ${id} ok=${payload.ok} failure=${failure}`);
  state.current = null;
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, "http://127.0.0.1");

  if (req.method === "OPTIONS") { send(res, 204, ""); return; }

  if (req.method === "GET" && url.pathname === "/job") {
    state.lastPollAt = Date.now();
    if (state.current) { send(res, 204, ""); return; }
    if (!state.queue.length) { send(res, 204, ""); return; }
    const job = state.queue.shift();
    state.current = job;
    console.log(`[server] dispatch ${job.snapshot_id}`);
    send(res, 200, {
      type: "capture",
      snapshot_id: job.snapshot_id,
      url: job.url,
      options: job.options || null,
      dom_max_bytes: DOM_MAX_BYTES
    });
    return;
  }

  if (req.method === "POST" && url.pathname === "/result") {
    try {
      const payload = JSON.parse(await readBody(req));
      storeResult(payload);
      send(res, 200, { stored: true });
    } catch (e) {
      console.error("[server] /result failed", e);
      send(res, 500, { error: String(e.message || e) });
    }
    return;
  }

  if (req.method === "POST" && url.pathname === "/progress") {
    try {
      const payload = JSON.parse(await readBody(req));
      state.progress.push(payload);
      if (state.progress.length > 500) state.progress.shift();
      if (payload.snapshot_id) {
        const dir = runDir(payload.snapshot_id);
        if (fs.existsSync(dir)) {
          fs.appendFileSync(path.join(dir, "progress.log"),
            `${new Date().toISOString()} ${payload.stage} ${payload.detail ? JSON.stringify(payload.detail) : ""}\n`);
        }
      }
      send(res, 200, { ok: true });
    } catch (e) {
      send(res, 400, { error: "bad payload" });
    }
    return;
  }

  if (req.method === "GET" && url.pathname === "/health") {
    send(res, 200, {
      ok: true,
      harness_connected: Date.now() - state.lastPollAt < STALE_MS,
      extractor_source: extractorHashes,
      runs_dir: RUNS_DIR
    });
    return;
  }

  send(res, 404, { error: "not found" });
});

// ── status file for compare.php ─────────────────────────────────────────────
setInterval(() => {
  writeJson(path.join(RUNS_DIR, "_server-status.json"), {
    pid: process.pid,
    started_at: new Date(state.startedAt).toISOString(),
    updated_at: new Date().toISOString(),
    harness_connected: Date.now() - state.lastPollAt < STALE_MS,
    last_poll_age_ms: state.lastPollAt ? Date.now() - state.lastPollAt : null,
    queue_length: state.queue.length,
    current: state.current ? state.current.snapshot_id : null,
    extractor_source: extractorHashes,
    runs_dir: RUNS_DIR,
    recent_progress: state.progress.slice(-8)
  });
}, 1000);

setInterval(claimJobs, POLL_MS);
claimJobs();

server.listen(PORT, BIND, () => {
  console.log(`[server] listening on http://${BIND}:${PORT}`);
  console.log(`[server] runs dir: ${RUNS_DIR}`);
  console.log(`[server] extractor copy identical to production: ${extractorHashes.identical_to_production}`);
  console.log("[server] waiting for the harness extension in the browser you already have open.");
  console.log("[server] load it once: edge://extensions → Developer mode → Load unpacked →");
  console.log(`[server]   ${HARNESS_DIR}`);
  console.log("[server] it opens an unfocused tab per capture and closes it again — no window, no new browser.");
});

process.on("SIGINT", () => { console.log("[server] bye"); process.exit(0); });
