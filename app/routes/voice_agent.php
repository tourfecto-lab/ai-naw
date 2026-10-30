<?php
// AI Voice & Sales Agent (Phase 1). Loaded from routes/api.php.
$router->get('/api/voice-agent/status','VoiceAgentController','status',['AuthMiddleware']);
$router->get('/api/voice-agent/settings','VoiceAgentController','getSettings',['AuthMiddleware']);
$router->post('/api/voice-agent/settings','VoiceAgentController','saveSettings',['AuthMiddleware']);
$router->get('/api/voice-agent/agents','VoiceAgentController','listAgents',['AuthMiddleware']);
$router->post('/api/voice-agent/agents','VoiceAgentController','createAgent',['AuthMiddleware']);
$router->post('/api/voice-agent/agents/{id}','VoiceAgentController','updateAgent',['AuthMiddleware']);
