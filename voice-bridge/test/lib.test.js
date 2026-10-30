'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const lib = require('../lib');

test('request signature matches the PHP algorithm (HMAC-SHA256 of "<ts>.<body>")', () => {
  // vector produced by PHP: VoiceBridgeAuth::signRequest('{"a":1}', 5000, 'sekret')
  assert.equal(lib.sign('{"a":1}', 5000, 'sekret'), '1c8f9d88a2f702e9a253782028e88bfe68a1f8b93217f549210d2d1b9fec9d69');
  const h = lib.signedHeaders('{"x":1}', 'tok', 's', 1234);
  assert.equal(h['X-Voice-Token'], 'tok');
  assert.equal(h['X-Voice-Timestamp'], '1234');
  assert.equal(h['X-Voice-Signature'], lib.sign('{"x":1}', 1234, 's'));
});

test('session uses mu-law both ways so no transcoding is needed, and exposes every tool', () => {
  const u = lib.sessionUpdate({ systemPrompt: 'P', voice: 'alloy' });
  assert.equal(u.session.input_audio_format, 'g711_ulaw');
  assert.equal(u.session.output_audio_format, 'g711_ulaw');
  assert.equal(u.session.turn_detection.type, 'server_vad');
  assert.equal(u.session.instructions, 'P');
  const names = u.session.tools.map((t) => t.name).sort();
  assert.deepEqual(names, ['check_availability', 'end_call', 'opt_out', 'qualify_lead', 'request_booking', 'search_trips', 'send_details', 'transfer_human', 'verify_identity']);
});

test('model audio goes to Twilio; a caller barge-in clears Twilio audio and cancels the response', () => {
  const ctx = { streamSid: 'MZ1' };
  assert.deepEqual(lib.handleModelEvent({ type: 'response.audio.delta', delta: 'QUJD' }, ctx), [{ kind: 'twilio_media', message: { event: 'media', streamSid: 'MZ1', media: { payload: 'QUJD' } } }]);
  const barge = lib.handleModelEvent({ type: 'input_audio_buffer.speech_started' }, ctx);
  assert.equal(barge[0].message.event, 'clear');
  assert.equal(barge[1].message.type, 'response.cancel');
  assert.deepEqual(lib.handleModelEvent({ type: 'response.audio.delta' }, ctx), []);
});

test('transcripts and tool calls are extracted; bad tool arguments do not crash', () => {
  const ctx = { streamSid: 'MZ1' };
  assert.deepEqual(lib.handleModelEvent({ type: 'conversation.item.input_audio_transcription.completed', transcript: ' hi ' }, ctx), [{ kind: 'transcript', role: 'caller', text: 'hi' }]);
  assert.deepEqual(lib.handleModelEvent({ type: 'response.audio_transcript.done', transcript: 'Hello' }, ctx), [{ kind: 'transcript', role: 'agent', text: 'Hello' }]);
  assert.deepEqual(lib.handleModelEvent({ type: 'response.function_call_arguments.done', name: 'search_trips', arguments: '{"query":"luxor"}', call_id: 'c1' }, ctx), [{ kind: 'tool', name: 'search_trips', args: { query: 'luxor' }, callId: 'c1' }]);
  assert.deepEqual(lib.handleModelEvent({ type: 'response.function_call_arguments.done', name: 'end_call', arguments: '{oops', call_id: 'c2' }, ctx)[0].args, {});
  assert.equal(lib.handleModelEvent({ type: 'error', error: { message: 'boom' } }, ctx)[0].message, 'boom');
});

test('tool results go back to the model followed by a request to continue', () => {
  const m = lib.toolOutputMessages('c1', { ok: true });
  assert.equal(m[0].item.call_id, 'c1');
  assert.equal(m[0].item.output, '{"ok":true}');
  assert.equal(m[1].type, 'response.create');
});

test('call control tools use the control endpoint; everything else the validated tool endpoint', () => {
  assert.deepEqual(lib.routeTool('transfer_human', { reason: 'complaint' }), { path: '/api/voice-bridge/control', body: { op: 'transfer', reason: 'complaint', outcome: undefined } });
  assert.equal(lib.routeTool('opt_out', {}).body.op, 'opt_out');
  assert.equal(lib.routeTool('end_call', { outcome: 'resolved' }).body.op, 'end');
  const t = lib.routeTool('check_availability', { product_id: 3, date: '2026-12-05', guests: 2 });
  assert.equal(t.path, '/api/voice-bridge/tool');
  assert.deepEqual(t.body, { action: { type: 'check_availability', product_id: 3, date: '2026-12-05', guests: 2 } });
});

test('configuration refuses to start without the secret, the key and an explicit model', () => {
  assert.throws(() => lib.config({ TOURFECTO_BASE_URL: 'https://x' }), /VOICE_BRIDGE_SECRET.*OPENAI_API_KEY.*OPENAI_REALTIME_MODEL/);
  const c = lib.config({ TOURFECTO_BASE_URL: 'https://x/', VOICE_BRIDGE_SECRET: 's', OPENAI_API_KEY: 'k', OPENAI_REALTIME_MODEL: 'm' });
  assert.equal(c.baseUrl, 'https://x');
  assert.equal(c.maxConcurrent, 20);
});
