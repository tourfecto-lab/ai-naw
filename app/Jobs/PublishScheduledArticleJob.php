<?php

/**
 * Tourfecto - Publish Scheduled Article Job
 * تنفيذ فعلي لجدولة نشر المقالات - بيتنفذ من نظام الـ Queue الموجود
 * بالفعل (جدول jobs + cron/process_queue.php)، مفيش نظام جديد. نفس
 * منطق AIController::publishArticle() بالظبط لكن بدون أي اعتماد على
 * سياق طلب HTTP (مفيش $this->get() ولا session - الـ Job ده بيتنفذ من
 * سطر أوامر عن طريق الكرون، ممكن بعد ساعات من وقت الجدولة الأصلي).
 *
 * @version 1.0.0
 */

class PublishScheduledArticleJob implements QueueJobInterface
{
    /** @var callable|null حقنة اختيارية للاختبارات: ($platform) => Publisher */
    private $publisherFactory;

    public function __construct(?callable $publisherFactory = null)
    {
        $this->publisherFactory = $publisherFactory;
    }

    /** إنشاء الـ publisher المناسب للمنصة (أو حقنة الاختبارات لو موجودة) */
    private function makePublisher(string $platform)
    {
        if ($this->publisherFactory !== null) {
            return call_user_func($this->publisherFactory, $platform);
        }
        return $platform === 'wordpress' ? new WordPressPublisher() : new CustomApiPublisher();
    }

    public function handle(array $payload): void
    {
        $articleId = (int) ($payload['article_id'] ?? 0);
        $websiteId = (int) ($payload['website_id'] ?? 0);
        $draft = (bool) ($payload['draft'] ?? false);

        $article = (new AIArticle())->find($articleId);
        if (!$article) {
            throw new Exception("Article #{$articleId} غير موجود - ملغي أو محذوف قبل موعد الجدولة");
        }

        // Idempotency: لو المقال مش في حالة "scheduled" فعليًا وقت التنفيذ
        // - يبقى العميل ألغى الجدولة أو نشره يدويًا أو جدوله تاني بمهمة
        // جديدة (schedule بيستبدل مش يضيف) في الفترة ما بين الجدولة
        // والتنفيذ. أي حالة تانية هنا يبقى الاستمرار في النشر غلط.
        if ($article->getAttribute('status') !== 'scheduled') {
            return;
        }

        $website = (new Website())->find($websiteId);
        if (!$website) {
            $this->markFailed($article, 'الموقع المرتبط بالجدولة لم يعد موجودًا');
            return;
        }

        $connection = $this->findPublishingConnection($websiteId);
        if (!$connection) {
            // العميل فصل الاتصال بعد ما جدول المقال - مفيش حاجة نعمها
            // غير إننا نبلغه ونوقف الجدولة دي (منقدرش نجمع بيانات اتصال
            // جديدة تلقائيًا من غير تفاعل بشري وقت التنفيذ الفعلي).
            $this->markFailed($article, 'الاتصال بالموقع اتفصل قبل موعد النشر المجدول - أعد الربط وانشر يدويًا');
            return;
        }

        $platform = (string) $connection->getAttribute('platform');
        $encryption = new Encryption();

        try {
            if (class_exists('ContentQualityService')) (new ContentQualityService(Database::getInstance()))->assertCurrent($article->toArray(),$websiteId);
            $title = (string) $article->getAttribute('title');
            $markdown = $article->contentForPresentation();
            $excerpt = (string) $article->getAttribute('meta_description');

            if ($platform === 'wordpress') {
                $credentials = $encryption->decrypt($connection->getAttribute('access_token'));
                [$username, $appPassword] = array_pad(explode(':', $credentials, 2), 2, '');
                $siteUrl = (string) $connection->getAttribute('external_location_id');
                $html = ContentFormatter::markdownToHtml($markdown);
                $publishStatus = $draft ? 'draft' : 'publish';

                $publisher = $this->makePublisher('wordpress');
                $existingPostId = $article->getAttribute('wp_post_id');
                // الـ post القديم اتمسح أو في سلة ووردبريس: ننشر مقال جديد بدل ما نرجّع القديم من السلة
                if ($existingPostId && !$publisher->postStillUsable($siteUrl, $username, $appPassword, (int) $existingPostId)) {
                    $existingPostId = null;
                }

                // الصورة البارزة (نفس منطق النشر اليدوي): أي فشل بيتنشر المقال من غيرها
                $featuredMedia = null;
                if (class_exists('ArticleImageService')) {
                    $img = ArticleImageService::make()->prepareForWordPress($article->toArray(), (int) $article->getAttribute('user_id'), $publisher, $siteUrl, $username, $appPassword);
                    $featuredMedia = $img['media_id'];
                    ArticleImageService::refreshModel($article);
                }

                $result = $existingPostId
                    ? $publisher->updatePost($siteUrl, $username, $appPassword, (int) $existingPostId, $title, $html, $excerpt, $featuredMedia, $publishStatus)
                    : $publisher->createPost($siteUrl, $username, $appPassword, $title, $html, $excerpt, $publishStatus, $featuredMedia, (string) $article->getAttribute('slug') ?: null);

                $newPostId = $result['success'] ? ($result['post_id'] ?? $existingPostId) : null;
            } else {
                $authToken = $connection->getAttribute('access_token') ? $encryption->decrypt($connection->getAttribute('access_token')) : '';
                $endpointUrl = (string) $connection->getAttribute('external_location_id');

                $publisher = $this->makePublisher('custom_api');
                $result = $publisher->publish($endpointUrl, $authToken, [
                    'article_id' => (int) $article->getAttribute('id'),
                    'title' => $title,
                    'content_html' => ContentFormatter::markdownToHtml($markdown),
                    'content_markdown' => $markdown,
                    'meta_description' => $excerpt,
                    'slug' => (string) $article->getAttribute('slug'),
                    'suggested_keywords' => $article->getSuggestedKeywordsArray(),
                ], false);

                $newPostId = null;
            }

            if (!$result['success']) {
                $connection->setAttribute('last_error', $result['error'] ?? 'Unknown error');
                $connection->save();
                $this->markFailed($article, $result['error'] ?? 'خطأ غير معروف أثناء النشر المجدول', 'publish_failed');
                return;
            }

            if ($platform === 'wordpress' && class_exists('ContentQualityService')) {
                $qualityArticle=$article->toArray();$qualityArticle['website_id']=$websiteId;
                try {
                    $seo=(new ContentQualityService(Database::getInstance()))->syncWordPress($qualityArticle,$publisher,$siteUrl,$username,$appPassword,(int)$newPostId,(string)($result['url']??''),$draft);
                    if (!$seo['success']) Logger::warning('Article published; SEO fields need connector update',['article_id'=>$articleId,'error'=>$seo['error']??'']);
                } catch (Throwable $seoError) { Logger::warning('Article SEO sync needs attention',['article_id'=>$articleId,'error'=>$seoError->getMessage()]); }
            }

            $article->setAttribute('website_id', $websiteId);
            if ($platform === 'wordpress') {
                $article->setAttribute('wp_post_id', $newPostId);
            }
            // مسودة ووردبريس مش منشورة: منسجّلهاش "published" ولا بنخزّن رابطها العام (بيدي 404 للزوار)
            if (!($draft && $platform === 'wordpress')) {
                if (!empty($result['url'])) {
                    $article->setAttribute('published_url', $result['url']);
                }
                $article->setAttribute('published_at', date('Y-m-d H:i:s'));
                $article->setAttribute('status', 'published');
            }
            $article->setAttribute('scheduled_job_id', null);
            $article->save();

            $connection->setAttribute('last_synced_at', date('Y-m-d H:i:s'));
            $connection->setAttribute('last_error', null);
            $connection->save();

            if (class_exists('Logger')) {
                Logger::info('Scheduled Article Published', [
                    'article_id' => $articleId,
                    'platform' => $platform,
                    'url' => $result['url'] ?? null,
                ]);
            }

            if (class_exists('Notification')) {
                Notification::notify(
                    (int) $article->getAttribute('user_id'),
                    'article_published',
                    'تم نشر مقالك المجدول بنجاح',
                    'مقال "' . $article->getAttribute('title') . '" اتنشر تلقائيًا في موعده المجدول.',
                    $article->getAttribute('published_url') ?: ('/ai/article/' . $articleId)
                );
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Scheduled Article Publish Error', ['article_id' => $articleId, 'message' => $e->getMessage()]);
            }
            $this->markFailed($article, 'خطأ غير متوقع أثناء النشر المجدول: ' . $e->getMessage());
        }
    }

    /**
     * @param string $status 'schedule_failed' للفشل قبل التنفيذ (اتصال/موقع مفقود)،
     *                       'publish_failed' للفشل أثناء طلب النشر الفعلي.
     */
    private function markFailed(AIArticle $article, string $reason, string $status = 'schedule_failed'): void
    {
        $article->setAttribute('status', $status);
        $article->setAttribute('error_message', $reason);
        $article->save();

        if (class_exists('Notification')) {
            Notification::notify(
                (int) $article->getAttribute('user_id'),
                'post_failed',
                'فشلت الجدولة التلقائية لمقالك',
                'مقال "' . $article->getAttribute('title') . '": ' . $reason,
                '/ai/article/' . $article->getAttribute('id')
            );
        }
    }

    /** نفس منطق AIController::findPublishingConnection - مكرر هنا لإن الـ Job كلاس مستقل بدون context كنترولر */
    private function findPublishingConnection(int $websiteId): ?PlatformConnection
    {
        foreach (['wordpress', 'custom_api'] as $platform) {
            $connections = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => $platform,
                'status' => 'connected',
            ], [], 1);
            if (!empty($connections)) {
                return $connections[0];
            }
        }
        return null;
    }
}
