<?php

/**
 * نشر مقال من محرك المحتوى بعد الموافقة: لو الموقع مربوط بـ WordPress أو
 * API نشر، بننشره فعليًا، وبعدين بنبعته لمحركات البحث (IndexNow).
 * @version 1.0.0
 */
class SeoContentAutomationHandler implements AutomationHandler
{
    public function execute(array $decision, string $finalText): array
    {
        // العميل عدّل عنوان المقال وهو بيوافق: نطبّق العنوان الجديد قبل النشر
        $newTitle = trim($finalText);
        if ($newTitle !== '' && $newTitle !== trim((string) ($decision['draft_text'] ?? ''))) {
            $db = Database::getInstance();
            $db->exec('UPDATE seo_content_items SET title = ? WHERE id = ?', [$newTitle, (int) $decision['subject_id']]);
            $db->exec('UPDATE ai_articles a INNER JOIN seo_content_items i ON i.article_id = a.id SET a.title = ? WHERE i.id = ?', [$newTitle, (int) $decision['subject_id']]);
        }
        return self::publishItem((int) $decision['subject_id'], (int) $decision['user_id']);
    }

    public static function publishItem(int $itemId, int $userId): array
    {
        $db = Database::getInstance();
        $rows = $db->query(
            'SELECT i.*, c.website_id, c.user_id FROM seo_content_items i INNER JOIN seo_content_campaigns c ON c.id = i.campaign_id WHERE i.id = ? LIMIT 1',
            [$itemId]
        );
        if (empty($rows) || (int) $rows[0]['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'المقال مش موجود'];
        }
        $item = $rows[0];
        if (!in_array($item['status'], ['generated'], true)) {
            return ['success' => true, 'note' => 'already_published'];
        }
        $websiteId = (int) $item['website_id'];
        $articleId = (int) ($item['article_id'] ?? 0);

        $published = false;
        $note = null;
        if ($articleId) {
            $conn = $db->query(
                "SELECT id FROM platform_connections WHERE website_id = ? AND platform IN ('wordpress','custom_api') AND status = 'connected' LIMIT 1",
                [$websiteId]
            );
            if (!empty($conn) && class_exists('PublishScheduledArticleJob')) {
                try {
                    $db->exec("UPDATE ai_articles SET status = 'scheduled' WHERE id = ? AND status IN ('completed','scheduled')", [$articleId]);
                    (new PublishScheduledArticleJob())->handle(['article_id' => $articleId, 'website_id' => $websiteId]);
                    $st = $db->query('SELECT status FROM ai_articles WHERE id = ?', [$articleId]);
                    $published = ($st[0]['status'] ?? '') === 'published';
                    if (!$published) {
                        return ['success' => false, 'error' => 'تعذر النشر على الموقع'];
                    }
                } catch (Throwable $e) {
                    return ['success' => false, 'error' => 'تعذر النشر: ' . $e->getMessage()];
                }
            } else {
                $note = 'الموقع مش مربوط بـ WordPress - المقال جاهز في صفحة المقالات وبيتبعت للفهرسة بس';
            }
        }

        $res = (new SeoContentService($db))->indexItem($itemId);
        if (empty($res['success']) && !$published) {
            return ['success' => false, 'error' => $res['error'] ?? 'تعذر الفهرسة'];
        }
        return ['success' => true, 'published' => $published, 'note' => $note];
    }
}
