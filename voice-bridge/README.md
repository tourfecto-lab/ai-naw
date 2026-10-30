# Tourfecto realtime voice bridge

Optional service for **streaming mode** (lowest latency). Without it, Tourfecto answers calls in turn mode (speech-gather, no extra paid service).

```
caller -> Twilio -> <Connect><Stream> wss://bridge/stream  ->  bridge  <-> realtime speech model (OpenAI Realtime or compatible)
                                                              |
                                                              +--> Tourfecto /api/voice-bridge/{context,tool,control,end}  (HMAC + per-call token)
```

Tourfecto keeps every decision: customer recognition, prices, availability, bookings, compliance, transfers, post-call actions.

## Run (needs your explicit go-ahead: it calls a paid model)
```bash
npm install
TOURFECTO_BASE_URL=https://tourfecto.pro VOICE_BRIDGE_SECRET=... OPENAI_API_KEY=... OPENAI_REALTIME_MODEL=<model id> npm start
```
Then in Tourfecto admin settings: `voice_stream_url=wss://<bridge host>/stream` and `voice_bridge_secret=<same secret>`.
Audio is mu-law 8 kHz end to end (`g711_ulaw`), so there is no transcoding. `GET /health` reports active calls. Terminate TLS in front (the stream URL must be `wss://`).
Never log audio or transcripts here; the transcript is sent to Tourfecto when the call ends.
