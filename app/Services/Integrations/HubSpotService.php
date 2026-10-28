<?php

/**
 * Tourfecto - HubSpot Integration
 * @version 1.0.0
 *
 * مزامنة جهات الاتصال والعملاء المحتملين مع HubSpot CRM.
 * المفتاح المتوقع هو Private App Access Token (أو Personal Access Token)
 * - بيتحط في HUBSPOT_API_KEY ويتبعت كـ Bearer.
 */

class HubSpotService extends BaseIntegrationService
{
    public function key(): string
    {
        return 'hubspot';
    }

    public function isConfigured(): bool
    {
        return $this->conf('HUBSPOT_API_KEY', 'HUBSPOT_API_KEY') !== '';
    }

    /**
     * إنشاء أو تحديث جهة اتصال (مطابقة بالإيميل).
     * @param string $email      البريد (مفتاح المطابقة الأساسي)
     * @param array  $properties خصائص إضافية مثل ['firstname'=>..., 'company'=>...]
     */
    public function createOrUpdateContact(string $email, array $properties = []): array
    {
        $payload = ['properties' => array_merge(['email' => $email], $properties)];

        return $this->httpJson('POST', 'https://api.hubapi.com/crm/v3/objects/contacts', [
            'Authorization: Bearer ' . $this->conf('HUBSPOT_API_KEY', 'HUBSPOT_API_KEY'),
        ], $payload);
    }

    /**
     * إنشاء صفقة (Deal) لعميل.
     */
    public function createDeal(string $dealName, string $stage, array $properties = []): array
    {
        $payload = ['properties' => array_merge([
            'dealname' => $dealName,
            'dealstage' => $stage,
        ], $properties)];

        return $this->httpJson('POST', 'https://api.hubapi.com/crm/v3/objects/deals', [
            'Authorization: Bearer ' . $this->conf('HUBSPOT_API_KEY', 'HUBSPOT_API_KEY'),
        ], $payload);
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'create_or_update_contact':
                return $this->createOrUpdateContact($params['email'] ?? '', $params['properties'] ?? []);
            case 'create_deal':
                return $this->createDeal($params['dealname'] ?? '', $params['stage'] ?? '', $params['properties'] ?? []);
            case 'test':
                return $this->httpJson('GET', 'https://api.hubapi.com/account-info/v3/details', [
                    'Authorization: Bearer ' . $this->conf('HUBSPOT_API_KEY', 'HUBSPOT_API_KEY'),
                ]);
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في HubSpotService", 'http_code' => 0];
        }
    }
}
