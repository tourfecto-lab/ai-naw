'use strict';
// Pure helpers for the Tourfecto realtime voice bridge (no network, no sockets), so they can be unit tested.
const crypto = require('node:crypto');

/** Must match VoiceBridgeAuth::signRequest in PHP: HMAC-SHA256 of "<timestamp>.<body>" with the shared secret. */
function sign(body, timestamp, secret) {
  return crypto.createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');
}

function signedHeaders(body, token, secret, now = Math.floor(Date.now() / 1000)) {
  return {
    'Content-Type': 'application/json',
    'X-Voice-Token': token,
    'X-Voice-Timestamp': String(now),
    'X-Voice-Signature': sign(body, now, secret),
  };
}

/** Tool schemas the model may call. They map 1:1 to the actions Tourfecto validates (VoiceReplyParser). */
const TOOLS = [
  { name: 'search_trips', description: 'Search this company\'s active trips by words (destination, type). Returns names, ids and real prices.', parameters: { type: 'object', properties: { query: { type: 'string' } }, required: ['query'] } },
  { name: 'check_availability', description: 'Check availability and the real total price for a trip on a date for N guests. Use the trip id from search_trips.', parameters: { type: 'object', properties: { product_id: { type: 'integer' }, date: { type: 'string', description: 'YYYY-MM-DD' }, guests: { type: 'integer' } }, required: ['product_id', 'date', 'guests'] } },
  { name: 'verify_identity', description: 'Verify the caller before discussing an existing booking: they must say a booking reference or the email on file.', parameters: { type: 'object', properties: { answer: { type: 'string' } }, required: ['answer'] } },
  { name: 'qualify_lead', description: 'Save what you learned about the caller\'s interest.', parameters: { type: 'object', properties: { name: { type: 'string' }, email: { type: 'string' }, party_size: { type: 'string' }, travel_dates: { type: 'string' }, budget: { type: 'string' }, interest: { type: 'string' }, intent_level: { type: 'string', enum: ['hot', 'warm', 'cold'] } } } },
  { name: 'request_booking', description: 'Record a booking REQUEST, only after the caller clearly agreed to trip, date, guests and total. It is not a confirmation.', parameters: { type: 'object', properties: { product_id: { type: 'integer' }, date: { type: 'string' }, guests: { type: 'integer' }, name: { type: 'string' } }, required: ['product_id', 'date', 'guests', 'name'] } },
  { name: 'send_details', description: 'Queue trip details or a payment link to be sent after the call to the caller\'s own number or the email on file.', parameters: { type: 'object', properties: { channel: { type: 'string', enum: ['whatsapp', 'email', 'any'] }, item: { type: 'string', enum: ['trip_details', 'payment_link'] }, product_id: { type: 'integer' } } } },
  { name: 'transfer_human', description: 'Transfer the call to a person: caller asks for one, is upset, has a complaint or payment problem, or you cannot help.', parameters: { type: 'object', properties: { reason: { type: 'string' } } } },
  { name: 'opt_out', description: 'The caller asked not to be called again.', parameters: { type: 'object', properties: {} } },
  { name: 'end_call', description: 'End the call after saying goodbye.', parameters: { type: 'object', properties: { outcome: { type: 'string', enum: ['resolved', 'lead_captured', 'callback_requested', 'not_interested', 'other'] } } } },
];
const CONTROL_TOOLS = new Set(['transfer_human', 'opt_out', 'end_call']);

function sessionUpdate({ systemPrompt, voice, temperature = 0.6 }) {
  return {
    type: 'session.update',
    session: {
      instructions: systemPrompt,
      voice,
      input_audio_format: 'g711_ulaw',   // Twilio Media Streams already speaks mu-law 8 kHz: no transcoding, lowest latency
      output_audio_format: 'g711_ulaw',
      turn_detection: { type: 'server_vad' },
      input_audio_transcription: { model: 'whisper-1' },
      temperature,
      tool_choice: 'auto',
      tools: TOOLS.map((t) => ({ type: 'function', ...t })),
    },
  };
}

/**
 * Translate one event from the speech model into what the bridge must do next. Pure.
 * ctx = { streamSid }. Returns a list of actions.
 */
function handleModelEvent(evt, ctx) {
  const out = [];
  switch (evt.type) {
    case 'response.audio.delta':
    case 'response.output_audio.delta':
      if (evt.delta) out.push({ kind: 'twilio_media', message: { event: 'media', streamSid: ctx.streamSid, media: { payload: evt.delta } } });
      break;
    case 'input_audio_buffer.speech_started': // caller barged in: stop the assistant talking
      out.push({ kind: 'twilio_clear', message: { event: 'clear', streamSid: ctx.streamSid } });
      out.push({ kind: 'model_send', message: { type: 'response.cancel' } });
      break;
    case 'conversation.item.input_audio_transcription.completed':
      if (evt.transcript && evt.transcript.trim()) out.push({ kind: 'transcript', role: 'caller', text: evt.transcript.trim() });
      break;
    case 'response.audio_transcript.done':
    case 'response.output_audio_transcript.done':
      if (evt.transcript && evt.transcript.trim()) out.push({ kind: 'transcript', role: 'agent', text: evt.transcript.trim() });
      break;
    case 'response.function_call_arguments.done': {
      let args = {};
      try { args = evt.arguments ? JSON.parse(evt.arguments) : {}; } catch (e) { args = {}; }
      out.push({ kind: 'tool', name: evt.name, args, callId: evt.call_id });
      break;
    }
    case 'error':
      out.push({ kind: 'model_error', message: (evt.error && evt.error.message) || 'model error' });
      break;
    default:
  }
  return out;
}

function toolOutputMessages(callId, result) {
  return [
    { type: 'conversation.item.create', item: { type: 'function_call_output', call_id: callId, output: JSON.stringify(result) } },
    { type: 'response.create' },
  ];
}

/** Which Tourfecto endpoint handles a tool call, and with what body. */
function routeTool(name, args) {
  if (CONTROL_TOOLS.has(name)) {
    const op = name === 'transfer_human' ? 'transfer' : name === 'opt_out' ? 'opt_out' : 'end';
    return { path: '/api/voice-bridge/control', body: { op, reason: args.reason, outcome: args.outcome } };
  }
  return { path: '/api/voice-bridge/tool', body: { action: { type: name, ...args } } };
}

function config(env) {
  const need = ['TOURFECTO_BASE_URL', 'VOICE_BRIDGE_SECRET', 'OPENAI_API_KEY', 'OPENAI_REALTIME_MODEL'];
  const missing = need.filter((k) => !env[k]);
  if (missing.length) throw new Error(`Missing environment: ${missing.join(', ')}`);
  return {
    baseUrl: env.TOURFECTO_BASE_URL.replace(/\/+$/, ''),
    secret: env.VOICE_BRIDGE_SECRET,
    openaiKey: env.OPENAI_API_KEY,
    model: env.OPENAI_REALTIME_MODEL,
    modelUrl: env.OPENAI_REALTIME_URL || 'wss://api.openai.com/v1/realtime', // overridable for tests / compatible providers
    port: Number(env.PORT || 8080),
    maxConcurrent: Math.max(1, Number(env.MAX_CONCURRENT_CALLS || 20)),
  };
}

module.exports = { sign, signedHeaders, TOOLS, CONTROL_TOOLS, sessionUpdate, handleModelEvent, toolOutputMessages, routeTool, config };
