'use strict';
// End-to-end through the real server.js with a fake Tourfecto (HTTP) and a fake speech model (WebSocket). No external network.
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { spawn } = require('node:child_process');
const { WebSocketServer, WebSocket } = require('ws');
const lib = require('../lib');

const SECRET = 'e2e-secret';
const freePort = () => new Promise((r) => { const s = http.createServer().listen(0, () => { const p = s.address().port; s.close(() => r(p)); }); });
const until = async (fn, ms = 4000) => { const t = Date.now(); while (Date.now() - t < ms) { const v = fn(); if (v) return v; await new Promise((r) => setTimeout(r, 15)); } throw new Error('timeout'); };

test('a simulated call: greeting, audio relay, signed tool call, barge-in, transcript saved at the end', async () => {
  // fake Tourfecto
  const calls = [];
  const tf = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      const ok = req.headers['x-voice-signature'] === lib.sign(body, Number(req.headers['x-voice-timestamp']), SECRET) && req.headers['x-voice-token'] === 'CALLTOKEN';
      calls.push({ path: req.url, body: JSON.parse(body || '{}'), signatureOk: ok });
      res.setHeader('Content-Type', 'application/json');
      if (!ok) { res.statusCode = 403; return res.end('{"success":false}'); }
      const data = req.url.endsWith('/context') ? { system_prompt: 'You are Layla', language: 'en' }
        : req.url.endsWith('/tool') ? { tool: 'search_trips', results: [{ id: 3, name: 'Luxor Day Trip', price: 120 }] }
        : { turns_saved: 2 };
      res.end(JSON.stringify({ success: true, data }));
    });
  });
  await new Promise((r) => tf.listen(0, r));

  // fake speech model
  const received = [];
  let modelSocket;
  const modelServer = new WebSocketServer({ port: 0 });
  await new Promise((r) => modelServer.on('listening', r));
  modelServer.on('connection', (ws, req) => {
    modelSocket = ws;
    received.push({ url: req.url, auth: req.headers.authorization });
    ws.on('message', (d) => received.push(JSON.parse(d.toString())));
  });

  const port = await freePort();
  const srv = spawn(process.execPath, [require('node:path').join(__dirname, '..', 'server.js')], {
    env: { ...process.env, PORT: String(port), TOURFECTO_BASE_URL: `http://127.0.0.1:${tf.address().port}`, VOICE_BRIDGE_SECRET: SECRET, OPENAI_API_KEY: 'sk-test', OPENAI_REALTIME_MODEL: 'test-model', OPENAI_REALTIME_URL: `ws://127.0.0.1:${modelServer.address().port}/v1/realtime` },
  });
  try {
    await new Promise((resolve, reject) => { srv.stdout.on('data', (d) => /listening/.test(String(d)) && resolve()); srv.on('exit', () => reject(new Error('bridge exited'))); setTimeout(() => reject(new Error('bridge did not start')), 4000); });

    const twilio = new WebSocket(`ws://127.0.0.1:${port}/stream`);
    const fromBridge = [];
    twilio.on('message', (d) => fromBridge.push(JSON.parse(d.toString())));
    await new Promise((r) => twilio.on('open', r));
    twilio.send(JSON.stringify({ event: 'connected' }));
    twilio.send(JSON.stringify({ event: 'start', start: { streamSid: 'MZ1', callSid: 'CA1', customParameters: { token: 'CALLTOKEN' } } }));

    // bridge asked Tourfecto for the context (signed) and configured the model with it, then asked it to speak first
    await until(() => received.find((m) => m.type === 'response.create'));
    const session = received.find((m) => m.type === 'session.update');
    assert.equal(session.session.instructions, 'You are Layla');
    assert.equal(session.session.input_audio_format, 'g711_ulaw');
    assert.equal(received[0].auth, 'Bearer sk-test');
    assert.match(received[0].url, /model=test-model/);
    assert.equal(calls[0].path, '/api/voice-bridge/context');
    assert.ok(calls[0].signatureOk, 'context request must be signed');

    // caller audio is relayed to the model untouched
    twilio.send(JSON.stringify({ event: 'media', media: { payload: 'AAAA' } }));
    const audioIn = await until(() => received.find((m) => m.type === 'input_audio_buffer.append'));
    assert.equal(audioIn.audio, 'AAAA');

    // model audio goes back to Twilio on the right stream
    modelSocket.send(JSON.stringify({ type: 'response.audio.delta', delta: 'QkJC' }));
    const media = await until(() => fromBridge.find((m) => m.event === 'media'));
    assert.deepEqual(media, { event: 'media', streamSid: 'MZ1', media: { payload: 'QkJC' } });

    // a tool call is executed by Tourfecto (signed) and the result returned to the model
    modelSocket.send(JSON.stringify({ type: 'response.function_call_arguments.done', name: 'search_trips', arguments: '{"query":"luxor"}', call_id: 'c1' }));
    const out = await until(() => received.find((m) => m.type === 'conversation.item.create'));
    assert.equal(out.item.call_id, 'c1');
    assert.equal(JSON.parse(out.item.output).results[0].name, 'Luxor Day Trip');
    const toolCall = calls.find((c) => c.path === '/api/voice-bridge/tool');
    assert.deepEqual(toolCall.body, { action: { type: 'search_trips', query: 'luxor' } });
    assert.ok(toolCall.signatureOk);

    // barge-in
    modelSocket.send(JSON.stringify({ type: 'input_audio_buffer.speech_started' }));
    await until(() => fromBridge.find((m) => m.event === 'clear'));
    await until(() => received.find((m) => m.type === 'response.cancel'));

    // transcripts are kept and sent to Tourfecto when the call ends
    modelSocket.send(JSON.stringify({ type: 'conversation.item.input_audio_transcription.completed', transcript: 'Hello there' }));
    modelSocket.send(JSON.stringify({ type: 'response.audio_transcript.done', transcript: 'Hi, how can I help?' }));
    await new Promise((r) => setTimeout(r, 80));
    twilio.send(JSON.stringify({ event: 'stop' }));
    const end = await until(() => calls.find((c) => c.path === '/api/voice-bridge/end'));
    assert.deepEqual(end.body.turns, [{ role: 'caller', text: 'Hello there' }, { role: 'agent', text: 'Hi, how can I help?' }]);
    assert.ok(end.signatureOk);
    twilio.close();
  } finally {
    srv.kill();
    modelServer.close();
    tf.close();
  }
});

test('a call without a token is refused and never reaches the model', async () => {
  const port = await freePort();
  const srv = spawn(process.execPath, [require('node:path').join(__dirname, '..', 'server.js')], { env: { ...process.env, PORT: String(port), TOURFECTO_BASE_URL: 'http://127.0.0.1:1', VOICE_BRIDGE_SECRET: SECRET, OPENAI_API_KEY: 'k', OPENAI_REALTIME_MODEL: 'm' } });
  try {
    await new Promise((resolve, reject) => { srv.stdout.on('data', (d) => /listening/.test(String(d)) && resolve()); setTimeout(() => reject(new Error('no start')), 4000); });
    const ws = new WebSocket(`ws://127.0.0.1:${port}/stream`);
    await new Promise((r) => ws.on('open', r));
    const closed = new Promise((r) => ws.on('close', (code) => r(code)));
    ws.send(JSON.stringify({ event: 'start', start: { streamSid: 'MZ', customParameters: {} } }));
    assert.equal(await closed, 1008);
  } finally { srv.kill(); }
});
