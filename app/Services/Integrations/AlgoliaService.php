<?php

/**
 * Tourfecto - Algolia Search Integration
 * @version 1.0.0
 *
 * بحث فوري (search-as-you-type) + فهرسة الكائنات. شوف ALGOLIA_* في .env.
 */

class AlgoliaService extends BaseIntegrationService
{
    public function key(): string
    {
        return 'algolia';
    }

    public function isConfigured(): bool
    {
        return $this->conf('ALGOLIA_APP_ID', 'ALGOLIA_APP_ID') !== ''
            && $this->conf('ALGOLIA_SEARCH_API_KEY', 'ALGOLIA_SEARCH_API_KEY') !== '';
    }

    private function appId(): string
    {
        return $this->conf('ALGOLIA_APP_ID', 'ALGOLIA_APP_ID');
    }

    /**
     * بحث في index معيّن (يستخدم مفتاح البحث العام للقراءة فقط).
     */
    public function searchIndex(string $index, string $query, array $options = []): array
    {
        $url = 'https://' . $this->appId() . '-dsn.algolia.net/1/indexes/' . rawurlencode($index) . '/query';
        $body = array_merge(['query' => $query, 'hitsPerPage' => 20], $options);

        return $this->httpJson('POST', $url, [
            'X-Algolia-Application-Id: ' . $this->appId(),
            'X-Algolia-API-Key: ' . $this->conf('ALGOLIA_SEARCH_API_KEY', 'ALGOLIA_SEARCH_API_KEY'),
        ], $body);
    }

    /**
     * فهرسة (إضافة/تحديث) كائن واحد في index. المفتاح objectID داخل الكائن.
     */
    public function indexObject(string $index, array $object): array
    {
        $objectId = $object['objectID'] ?? null;
        if ($objectId === null) {
            return ['success' => false, 'data' => null, 'error' => 'objectID مطلوب للفهرسة', 'http_code' => 0];
        }

        $url = 'https://' . $this->appId() . '.algolia.net/1/indexes/' . rawurlencode($index) . '/batch';
        return $this->httpJson('POST', $url, [
            'X-Algolia-Application-Id: ' . $this->appId(),
            'X-Algolia-API-Key: ' . $this->conf('ALGOLIA_WRITE_API_KEY', 'ALGOLIA_WRITE_API_KEY'),
        ], [
            'requests' => [['action' => 'addObject', 'body' => $object]],
        ]);
    }

    /**
     * فهرسة دفعة كائنات مرة واحدة (أسرع بكتير من كائن كائن).
     */
    public function indexObjects(string $index, array $objects): array
    {
        $requests = [];
        foreach ($objects as $object) {
            $requests[] = ['action' => 'addObject', 'body' => $object];
        }

        $url = 'https://' . $this->appId() . '.algolia.net/1/indexes/' . rawurlencode($index) . '/batch';
        return $this->httpJson('POST', $url, [
            'X-Algolia-Application-Id: ' . $this->appId(),
            'X-Algolia-API-Key: ' . $this->conf('ALGOLIA_WRITE_API_KEY', 'ALGOLIA_WRITE_API_KEY'),
        ], ['requests' => $requests]);
    }

    public function deleteObject(string $index, string $objectId): array
    {
        $url = 'https://' . $this->appId() . '.algolia.net/1/indexes/' . rawurlencode($index) . '/batch';
        return $this->httpJson('POST', $url, [
            'X-Algolia-Application-Id: ' . $this->appId(),
            'X-Algolia-API-Key: ' . $this->conf('ALGOLIA_WRITE_API_KEY', 'ALGOLIA_WRITE_API_KEY'),
        ], [
            'requests' => [['action' => 'deleteObject', 'body' => ['objectID' => $objectId]]],
        ]);
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'search':
                return $this->searchIndex($params['index'] ?? '', $params['query'] ?? '', $params['options'] ?? []);
            case 'index_object':
                return $this->indexObject($params['index'] ?? '', $params['object'] ?? []);
            case 'index_objects':
                return $this->indexObjects($params['index'] ?? '', $params['objects'] ?? []);
            case 'delete_object':
                return $this->deleteObject($params['index'] ?? '', $params['object_id'] ?? '');
            case 'test':
                return $this->httpJson('GET', 'https://' . $this->appId() . '-dsn.algolia.net/1/indexes', [
                    'X-Algolia-Application-Id: ' . $this->appId(),
                    'X-Algolia-API-Key: ' . $this->conf('ALGOLIA_SEARCH_API_KEY', 'ALGOLIA_SEARCH_API_KEY'),
                ]);
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في AlgoliaService", 'http_code' => 0];
        }
    }
}
