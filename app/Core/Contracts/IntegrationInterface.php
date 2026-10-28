<?php
/**
 * Tourfecto - Integration Contract
 * @version 1.0.0
 *
 * أي API خارجي جديد (OpenAI, Slack, HubSpot... إلخ) لازم يعمل implement
 * للـ interface ده. الهدف: IntegrationManager يقدر يتعامل مع أي API بنفس
 * الطريقة، من غير ما يعرف تفاصيله الداخلية.
 */
interface IntegrationInterface {

    /**
     * اسم الـ platform الفريد زي ما هيتخزن في عمود platform_connections.platform
     * مثال: 'openai', 'slack', 'hubspot'
     */
    public function key(): string;

    /**
     * هل الإعدادات الأساسية (API key عام أو OAuth client id/secret) موجودة
     * في .env؟ ده منفصل عن "هل العميل ربط حسابه" (ده بيتحدد من platform_connections).
     */
    public function isConfigured(): bool;

    /**
     * نوع الربط: 'api_key' (مفتاح واحد يغطي كل العملاء زي TripAdvisor/Stripe)
     * أو 'oauth' (كل عميل بيربط حسابه هو زي Google/Meta)
     */
    public function authType(): string;

    /**
     * تنفيذ طلب فعلي على الـ API. $context بتحتوي أي بيانات لازمة
     * (access_token للعميل لو oauth، معرف الموقع... إلخ)
     * @return array ['success' => bool, 'data' => mixed, 'error' => ?string]
     */
    public function request(string $action, array $params = [], array $context = []): array;
}
