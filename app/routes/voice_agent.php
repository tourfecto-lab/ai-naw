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

// Phase 3: phone numbers
$router->get('/api/voice-agent/numbers','VoiceNumberController','index',['AuthMiddleware']);
$router->get('/api/voice-agent/markets','VoiceNumberController','markets',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/quote','VoiceNumberController','quote',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/orders/{id}/approve','VoiceNumberController','approve',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/connect','VoiceNumberController','connect',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/{id}/verify/start','VoiceNumberController','verifyStart',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/{id}/verify/confirm','VoiceNumberController','verifyConfirm',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/{id}/test','VoiceNumberController','test',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/{id}/assign','VoiceNumberController','assign',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/{id}/release','VoiceNumberController','release',['AuthMiddleware']);

// Phase 4: inbound AI calls (public, provider-signed) and the realtime bridge API (public, token + HMAC)
$router->post('/webhooks/voice/{provider}/{tenant}/inbound','VoiceWebhookController','inbound',[]);
$router->post('/webhooks/voice/{provider}/{tenant}/turn','VoiceWebhookController','turn',[]);
$router->post('/api/voice-bridge/context','VoiceBridgeController','context',[]);
$router->post('/api/voice-bridge/tool','VoiceBridgeController','tool',[]);
$router->post('/api/voice-bridge/end','VoiceBridgeController','end',[]);
// Phase 4: call history for the account
$router->get('/api/voice-agent/calls','VoiceCallsController','index',['AuthMiddleware']);
$router->get('/api/voice-agent/calls/{id}','VoiceCallsController','show',['AuthMiddleware']);

// Phase 5: outbound calls, consent, do-not-call
$router->post('/webhooks/voice/{provider}/{tenant}/outbound','VoiceWebhookController','outbound',[]);
$router->get('/api/voice-agent/outbound/purposes','VoiceOutboundController','purposes',['AuthMiddleware']);
$router->get('/api/voice-agent/outbound/jobs','VoiceOutboundController','jobs',['AuthMiddleware']);
$router->post('/api/voice-agent/outbound/jobs','VoiceOutboundController','createJob',['AuthMiddleware']);
$router->post('/api/voice-agent/outbound/jobs/{id}/cancel','VoiceOutboundController','cancelJob',['AuthMiddleware']);
$router->post('/api/voice-agent/consents','VoiceOutboundController','grantConsent',['AuthMiddleware']);
$router->post('/api/voice-agent/consents/withdraw','VoiceOutboundController','withdrawConsent',['AuthMiddleware']);
$router->get('/api/voice-agent/dnc','VoiceOutboundController','dnc',['AuthMiddleware']);
$router->post('/api/voice-agent/dnc','VoiceOutboundController','addDnc',['AuthMiddleware']);
$router->post('/api/voice-agent/dnc/remove','VoiceOutboundController','removeDnc',['AuthMiddleware']);

// Phase 2 (platform admin): provider health, catalogue, errors and costs across companies
$router->get('/api/admin/voice/providers','VoiceAdminController','providers',['AuthMiddleware','AdminMiddleware']);
$router->post('/api/admin/voice/providers/{provider}/test','VoiceAdminController','test',['AuthMiddleware','AdminMiddleware']);
$router->get('/api/admin/voice/providers/{provider}/countries','VoiceAdminController','countries',['AuthMiddleware','AdminMiddleware']);
$router->get('/api/admin/voice/providers/{provider}/numbers','VoiceAdminController','numbers',['AuthMiddleware','AdminMiddleware']);
$router->get('/api/admin/voice/errors','VoiceAdminController','errors',['AuthMiddleware','AdminMiddleware']);
$router->get('/api/admin/voice/costs','VoiceAdminController','costs',['AuthMiddleware','AdminMiddleware']);

// Phase 3: default number
$router->post('/api/voice-agent/numbers/{id}/default','VoiceNumberController','setDefault',['AuthMiddleware']);

// Phase 4: realtime bridge call control
$router->post('/api/voice-bridge/control','VoiceBridgeController','control',[]);

// Phase 5: call lists (campaigns)
$router->get('/api/voice-agent/outbound/campaigns','VoiceOutboundController','campaigns',['AuthMiddleware']);
$router->post('/api/voice-agent/outbound/campaigns','VoiceOutboundController','createCampaign',['AuthMiddleware']);
$router->get('/api/voice-agent/outbound/campaigns/{id}','VoiceOutboundController','campaignStats',['AuthMiddleware']);
$router->post('/api/voice-agent/outbound/campaigns/{id}/recipients','VoiceOutboundController','addRecipients',['AuthMiddleware']);
$router->post('/api/voice-agent/outbound/campaigns/{id}/status','VoiceOutboundController','campaignStatus',['AuthMiddleware']);

// Phase 7: assistants, routing, handoff staff, hours
$router->post('/webhooks/voice/{provider}/{tenant}/whisper','VoiceWebhookController','whisper',[]);
$router->get('/api/voice-agent/staff','VoiceAssistantController','staff',['AuthMiddleware']);
$router->post('/api/voice-agent/staff','VoiceAssistantController','createStaff',['AuthMiddleware']);
$router->post('/api/voice-agent/staff/{id}','VoiceAssistantController','updateStaff',['AuthMiddleware']);
$router->post('/api/voice-agent/staff/{id}/delete','VoiceAssistantController','deleteStaff',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/{id}/routing','VoiceAssistantController','setRouting',['AuthMiddleware']);
$router->get('/api/voice-agent/hours','VoiceAssistantController','hours',['AuthMiddleware']);

// Phase 8: analytics, supervisor reviews, ad attribution
$router->get('/api/voice-agent/analytics','VoiceAnalyticsController','overview',['AuthMiddleware']);
$router->get('/api/voice-agent/analytics/reviews','VoiceAnalyticsController','reviews',['AuthMiddleware']);
$router->post('/api/voice-agent/numbers/{id}/attribution','VoiceAnalyticsController','setAttribution',['AuthMiddleware']);
$router->get('/api/admin/voice/analytics','VoiceAdminController','analytics',['AuthMiddleware','AdminMiddleware']);
