'use strict';
// Tourfecto realtime voice bridge. Twilio Media Streams <-> a realtime speech model.
// All business logic (customer recognition, prices, bookings, compliance, transfers) stays in Tourfecto: this process only relays audio
// and forwards tool calls. It holds no customer data beyond the live call and keeps no logs of audio.
const http = require('node:http');
const { WebSocketServer, WebSocket } = require('ws');
const lib = require('./lib');

const cfg = lib.config(process.env);
let active = 0;

async function tourfecto(path, body, token) {
  const raw = JSON.stringify(body || {});
  const res = await fetch(cfg.baseUrl + path, { method: 'POST', headers: lib.signedHeaders(raw, token, cfg.secret), body: raw });
  const json = await res.json().catch(() => ({}));
  if (!res.ok || !json.success) throw new Error(`Tourfecto ${path} -> HTTP ${res.status}`);
  return json.data;
}

const server = http.createServer((req, res) => {
  if (req.url === '/health') { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ ok: true, active })); return; }
  res.writeHead(404); res.end();
});
const wss = new WebSocketServer({ server, path: '/stream' });

wss.on('connection', (twilio) => {
  if (active >= cfg.maxConcurrent) { twilio.close(1013, 'at capacity'); return; }
  active++;
  let streamSid = null;
  let token = null;
  let model = null;
  const transcript = [];
  let ended = false;

  const finish = async () => {
    if (ended) return;
    ended = true;
    active--;
    try { if (token) await tourfecto('/api/voice-bridge/end', { turns: transcript.slice(-200) }, token); } catch (e) { console.error('end failed:', e.message); }
    try { model && model.close(); } catch (e) { /* already closed */ }
  };

  twilio.on('message', async (data) => {
    let m;
    try { m = JSON.parse(data.toString()); } catch (e) { return; }
    if (m.event === 'start') {
      streamSid = m.start.streamSid;
      token = m.start.customParameters && m.start.customParameters.token;
      if (!token) { twilio.close(1008, 'no token'); return; }
      try {
        const ctx = await tourfecto('/api/voice-bridge/context', {}, token);
        model = new WebSocket(`${cfg.modelUrl}?model=${encodeURIComponent(cfg.model)}`, { headers: { Authorization: `Bearer ${cfg.openaiKey}`, 'OpenAI-Beta': 'realtime=v1' } });
        model.on('open', () => {
          model.send(JSON.stringify(lib.sessionUpdate({ systemPrompt: ctx.system_prompt, voice: process.env.OPENAI_REALTIME_VOICE || 'alloy' })));
          model.send(JSON.stringify({ type: 'response.create' })); // the assistant speaks first (greeting is part of the prompt)
        });
        model.on('message', async (raw) => {
          let evt;
          try { evt = JSON.parse(raw.toString()); } catch (e) { return; }
          for (const a of lib.handleModelEvent(evt, { streamSid })) {
            if (a.kind === 'twilio_media' || a.kind === 'twilio_clear') twilio.send(JSON.stringify(a.message));
            else if (a.kind === 'model_send') model.send(JSON.stringify(a.message));
            else if (a.kind === 'transcript') transcript.push({ role: a.role, text: a.text });
            else if (a.kind === 'model_error') console.error('model error:', a.message);
            else if (a.kind === 'tool') {
              let result;
              try {
                const r = lib.routeTool(a.name, a.args);
                result = await tourfecto(r.path, r.body, token);
              } catch (e) { result = { error: 'tool_unavailable' }; }
              for (const out of lib.toolOutputMessages(a.callId, result)) model.send(JSON.stringify(out));
            }
          }
        });
        model.on('close', finish);
        model.on('error', (e) => console.error('model socket:', e.message));
      } catch (e) { console.error('start failed:', e.message); twilio.close(1011, 'setup failed'); }
    } else if (m.event === 'media' && model && model.readyState === WebSocket.OPEN) {
      model.send(JSON.stringify({ type: 'input_audio_buffer.append', audio: m.media.payload }));
    } else if (m.event === 'stop') {
      finish();
    }
  });
  twilio.on('close', finish);
  twilio.on('error', (e) => console.error('twilio socket:', e.message));
});

server.listen(cfg.port, () => console.log(`voice bridge listening on :${cfg.port} (max ${cfg.maxConcurrent} calls)`));
