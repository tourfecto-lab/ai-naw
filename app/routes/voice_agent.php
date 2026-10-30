<?php
// AI Voice & Sales Agent (Phase 1). Loaded from routes/api.php.
$router->get('/api/voice-agent/status','VoiceAgentController','status',['AuthMiddleware']);
$router->get('/api/voice-agent/settings','VoiceAgentController','getSettings',['AuthMiddleware']);
$router->post('/api/voice-agent/settings','VoiceAgentController','saveSettings',['AuthMiddleware']);
$router->get('/api/voice-agent/agents','VoiceAgentController','listAgents',['AuthMiddleware']);
$router->post('/api/voice-agent/agents','VoiceAgentController','createAgent',['AuthMiddleware']);
$router->post('/api/voice-agent/agents/{id}','VoiceAgentController','updateAgent',['AuthMiddleware']);

// Phase 2: telephony providers
$router->get('/api/voice-agent/providers','VoiceTelephonyController','providers',['AuthMiddleware']);
$router->post('/api/voice-agent/providers/{provider}/credentials','VoiceTelephonyController','saveCredentials',['AuthMiddleware']);
$router->post('/api/voice-agent/providers/{provider}/remove','VoiceTelephonyController','removeCredentials',['AuthMiddleware']);
$router->post('/api/voice-agent/providers/{provider}/test','VoiceTelephonyController','test',['AuthMiddleware']);
$router->get('/api/voice-agent/providers/{provider}/countries','VoiceTelephonyController','countries',['AuthMiddleware']);
$router->get('/api/voice-agent/providers/{provider}/numbers','VoiceTelephonyController','searchNumbers',['AuthMiddleware']);
$router->get('/api/voice-agent/provider-errors','VoiceTelephonyController','errors',['AuthMiddleware']);
$router->get('/api/voice-agent/costs','VoiceTelephonyController','costs',['AuthMiddleware']);
$router->post('/webhooks/voice/{provider}/{tenant}/status','VoiceWebhookController','status',[]);
