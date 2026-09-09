'use strict';
/**
 * SAMEH Local AI Worker (Owner Windows).
 * Core NEVER calls Ollama — only this process does (localhost).
 * Logs never include tokens/secrets/full prompts when secrets suspected.
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');
const { URL } = require('url');

const ROOT = __dirname;
const PID_FILE = path.join(ROOT, 'worker.pid');
const LOG_FILE = path.join(ROOT, 'worker.log');
const CONFIG_FILE = path.join(ROOT, 'config.json');

function log(msg) {
  const line = `[${new Date().toISOString()}] ${redact(String(msg))}\n`;
  try { fs.appendFileSync(LOG_FILE, line); } catch (_) {}
  process.stdout.write(line);
}

function redact(s) {
  return s
    .replace(/sw_[a-f0-9]{20,}/gi, '[REDACTED_TOKEN]')
    .replace(/Bearer\s+[A-Za-z0-9._\-]+/gi, 'Bearer [REDACTED]')
    .replace(/hmac_secret|connector_token|pairing_token|shared_secret/gi, '[REDACTED_KEY]');
}

function loadConfig() {
  if (!fs.existsSync(CONFIG_FILE)) {
    console.error('Missing config.json — copy config.example.json');
    process.exit(1);
  }
  const cfg = JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
  if (!cfg.core_base_url || !cfg.worker_token) {
    console.error('core_base_url and worker_token required');
    process.exit(1);
  }
  if (!/^https:\/\//i.test(cfg.core_base_url)) {
    console.error('core_base_url must be HTTPS');
    process.exit(1);
  }
  cfg.ollama_base_url = (cfg.ollama_base_url || 'http://127.0.0.1:11434').replace(/\/$/, '');
  cfg.poll_ms = Number(cfg.poll_ms || 2500);
  cfg.idle_sleep_ms = Number(cfg.idle_sleep_ms || 5000);
  cfg.request_timeout_ms = Number(cfg.request_timeout_ms || 120000);
  cfg.prefer_hermes = cfg.prefer_hermes !== false;
  return cfg;
}

function writePid() {
  fs.writeFileSync(PID_FILE, String(process.pid), 'utf8');
}

function clearPid() {
  try { if (fs.existsSync(PID_FILE)) fs.unlinkSync(PID_FILE); } catch (_) {}
}

function requestJson(urlStr, opts = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const lib = u.protocol === 'https:' ? https : http;
    const body = opts.body ? Buffer.from(opts.body) : null;
    const req = lib.request({
      protocol: u.protocol,
      hostname: u.hostname,
      port: u.port || (u.protocol === 'https:' ? 443 : 80),
      path: u.pathname + (u.search || ''),
      method: opts.method || 'GET',
      headers: Object.assign({
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...(body ? { 'Content-Length': body.length } : {}),
      }, opts.headers || {}),
      timeout: opts.timeout || 30000,
    }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        let data = null;
        try { data = JSON.parse(raw); } catch (_) { data = { raw }; }
        resolve({ status: res.statusCode || 0, data });
      });
    });
    req.on('error', reject);
    req.on('timeout', () => { req.destroy(); reject(new Error('timeout')); });
    if (body) req.write(body);
    req.end();
  });
}

function nonce() {
  return require('crypto').randomBytes(16).toString('hex');
}

async function coreApi(cfg, route, method, payload) {
  const ts = String(Math.floor(Date.now() / 1000));
  const n = nonce();
  const url = cfg.core_base_url.replace(/\/$/, '') + route;
  return requestJson(url, {
    method,
    timeout: cfg.request_timeout_ms,
    headers: {
      'X-Sameh-Worker-Token': cfg.worker_token,
      'X-Sameh-Timestamp': ts,
      'X-Sameh-Nonce': n,
    },
    body: payload ? JSON.stringify(payload) : null,
  });
}

async function ollamaTags(cfg) {
  const r = await requestJson(cfg.ollama_base_url + '/api/tags', { method: 'GET', timeout: 10000 });
  const models = (r.data && r.data.models) ? r.data.models.map((m) => m.name || m.model).filter(Boolean) : [];
  return models;
}

function pickModel(models, preferHermes) {
  if (!models.length) return null;
  if (preferHermes) {
    const h = models.find((m) => /hermes/i.test(m));
    if (h) return h;
  }
  return models[0];
}

async function ollamaChat(cfg, model, messages) {
  const r = await requestJson(cfg.ollama_base_url + '/api/chat', {
    method: 'POST',
    timeout: cfg.request_timeout_ms,
    body: JSON.stringify({ model, messages, stream: false }),
  });
  if (r.status >= 200 && r.status < 300 && r.data && r.data.message) {
    return { ok: true, content: r.data.message.content || '' };
  }
  // fallback generate
  const prompt = messages.map((m) => `${m.role}: ${m.content}`).join('\n');
  const g = await requestJson(cfg.ollama_base_url + '/api/generate', {
    method: 'POST',
    timeout: cfg.request_timeout_ms,
    body: JSON.stringify({ model, prompt, stream: false }),
  });
  if (g.status >= 200 && g.status < 300 && g.data && g.data.response) {
    return { ok: true, content: g.data.response };
  }
  return { ok: false, error: 'ollama_failed_' + (r.status || g.status) };
}

function parseAiResult(content) {
  let text = String(content || '').trim();
  text = text.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');
  let data = null;
  try { data = JSON.parse(text); } catch (_) {
    const m = text.match(/\{[\s\S]*\}/);
    if (m) { try { data = JSON.parse(m[0]); } catch (__) {} }
  }
  if (!data || typeof data !== 'object') {
    return { ok: true, summary_ar: text.slice(0, 2000), findings: [] };
  }
  if (typeof data.ok !== 'boolean') data.ok = true;
  if (typeof data.summary_ar !== 'string') data.summary_ar = text.slice(0, 500);
  if (!Array.isArray(data.findings)) data.findings = [];
  return data;
}

async function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

async function main() {
  const cfg = loadConfig();
  writePid();
  log('Worker starting pid=' + process.pid);
  let backoff = 1000;
  let models = [];

  // Pair / heartbeat bootstrap
  try {
    models = await ollamaTags(cfg);
    log('Ollama models: ' + models.join(', '));
  } catch (e) {
    log('Ollama tags failed: ' + e.message);
  }

  try {
    const pair = await coreApi(cfg, '/api/worker/pair', 'POST', {
      hostname: require('os').hostname(),
      version: '12.1.0',
      models,
    });
    log('Pair status=' + pair.status + ' ok=' + !!(pair.data && pair.data.ok));
  } catch (e) {
    log('Pair error: ' + e.message);
  }

  const shutdown = () => {
    log('Shutdown signal');
    clearPid();
    process.exit(0);
  };
  process.on('SIGINT', shutdown);
  process.on('SIGTERM', shutdown);

  while (true) {
    try {
      models = await ollamaTags(cfg).catch(() => models);
      await coreApi(cfg, '/api/worker/heartbeat', 'POST', { models }).catch(() => null);

      const claim = await coreApi(cfg, '/api/worker/jobs/claim', 'POST', { limit: 1 });
      backoff = 1000;
      const job = claim.data && (claim.data.job || (claim.data.jobs && claim.data.jobs[0]));
      if (!job) {
        await sleep(cfg.idle_sleep_ms);
        continue;
      }
      log('Claimed job #' + job.id + ' agent=' + (job.agent_name || ''));
      const model = pickModel(models, cfg.prefer_hermes) || job.model_requested || 'llama3';
      const payload = job.payload || {};
      // Never include secrets in prompt
      const safePayload = JSON.parse(redact(JSON.stringify(payload)));
      const messages = [
        { role: 'system', content: 'You are a SAMEH SEO analysis agent. Reply with JSON only: {"ok":true,"summary_ar":"...","findings":[{"code":"","severity":"info|low|medium|high","title":"","detail":""}]}. Never echo secrets.' },
        { role: 'user', content: JSON.stringify({
          agent: job.agent_name,
          goal: safePayload.goal || '',
          instruction: safePayload.instruction || '',
          evidence: safePayload.evidence || {},
        }) },
      ];
      const ai = await ollamaChat(cfg, model, messages);
      if (!ai.ok) {
        await coreApi(cfg, '/api/worker/jobs/' + job.id + '/fail', 'POST', { error: ai.error || 'ollama_error' });
        await sleep(cfg.poll_ms);
        continue;
      }
      const result = parseAiResult(ai.content);
      await coreApi(cfg, '/api/worker/jobs/' + job.id + '/complete', 'POST', {
        result,
        model_used: model,
      });
      log('Completed job #' + job.id + ' model=' + model);
      await sleep(cfg.poll_ms);
    } catch (e) {
      log('Loop error: ' + e.message + ' backoff=' + backoff);
      await sleep(backoff);
      backoff = Math.min(backoff * 2, 30000);
    }
  }
}

if (require.main === module) {
  main().catch((e) => {
    log('Fatal: ' + e.message);
    clearPid();
    process.exit(1);
  });
}

module.exports = { redact, pickModel, parseAiResult };
