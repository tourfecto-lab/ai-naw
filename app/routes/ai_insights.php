<?php
// AI insights (cited sources, sentiment, AI crawler visits). Loaded from routes/api.php.
$router->get('/ai-insights','AiInsightsController','index',['AuthMiddleware']);
$router->get('/api/ai-insights/data','AiInsightsController','data',['AuthMiddleware']);
$router->post('/api/ai-insights/sentiment','AiInsightsController','sentiment',['AuthMiddleware']);
$router->post('/api/ai-insights/crawler-token','AiInsightsController','crawlerToken',['AuthMiddleware']);
$router->post('/api/ai-insights/crawler-log','AiInsightsController','crawlerLog',['AuthMiddleware']);
$router->post('/api/public/ai-crawlers/ingest','AiInsightsController','ingest');
$router->get('/api/admin/ai-insights/claude','AiInsightsController','claudeSettings',['AuthMiddleware','AdminMiddleware']);
$router->post('/api/admin/ai-insights/claude','AiInsightsController','claudeSettings',['AuthMiddleware','AdminMiddleware']);
