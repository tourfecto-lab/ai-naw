<?php
/**
 * Tourfecto - Notification Model
 * @version 1.0.0
 */
class Notification extends Model {
    protected $table = 'notifications';
    protected $fillable = ['user_id', 'type', 'title', 'body', 'link', 'read_at'];

    public static function notify(int $userId, string $type, string $title, string $body = '', string $link = ''): void {
        try {
            $n = new self([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body ?: null,
                'link' => $link ?: null,
            ]);
            $n->save();
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Notification::notify failed: ' . $e->getMessage());
            }
        }
    }
}
