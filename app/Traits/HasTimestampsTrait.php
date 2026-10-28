<?php

/**
 * Tourfecto - Has Timestamps Trait
 * @version 1.0.0
 *
 * سلوك مشترك لأي كلاس (Repository/Service) بيحتاج يتعامل مع created_at/
 * updated_at بشكل موحّد، بدل ما كل واحد يكرر date('Y-m-d H:i:s') بنفسه.
 */
trait HasTimestampsTrait
{
    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    protected function withTimestamps(array $data, bool $isNew = true): array
    {
        if ($isNew && !isset($data['created_at'])) {
            $data['created_at'] = $this->now();
        }
        $data['updated_at'] = $this->now();
        return $data;
    }

    protected function isRecent(?string $datetime, int $withinSeconds): bool
    {
        if (!$datetime) {
            return false;
        }
        return (time() - strtotime($datetime)) <= $withinSeconds;
    }
}
