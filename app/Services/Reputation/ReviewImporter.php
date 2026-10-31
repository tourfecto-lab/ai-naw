<?php

/**
 * Reputation: import reviews from other sites by CSV (Booking.com, Expedia, Trustpilot, anything else).
 * These platforms do not give businesses an open API to read reviews, so the export file from their extranet is the
 * real, supported route. Imported reviews are stored like any other review (source_platform, sentiment from the stars,
 * shown in the list, stats and topic analysis). No AI call is made, no alert is sent for old reviews, and re-importing
 * the same file never creates duplicates.
 *
 * Columns (header row required, any order, case-insensitive): rating (or score), review_text (or comment/review),
 * reviewer_name (or name/guest), review_date (or date), external_id (or id) optional, language optional.
 */
class ReviewImporter
{
    public const SOURCES = ['booking', 'expedia', 'trustpilot', 'custom', 'other'];
    public const MAX_ROWS = 500;
    public const MAX_BYTES = 1048576;

    private const ALIASES = [
        'rating' => ['rating', 'score', 'stars', 'overall rating', 'reviewer score', 'review score'],
        'review_text' => ['review_text', 'review', 'comment', 'text', 'review text', 'positive review', 'content'],
        'reviewer_name' => ['reviewer_name', 'name', 'guest', 'guest name', 'reviewer', 'author'],
        'review_date' => ['review_date', 'date', 'review date', 'created', 'published', 'date of review'],
        'external_id' => ['external_id', 'id', 'review id', 'review_id', 'reference'],
        'language' => ['language', 'lang', 'review_language'],
    ];

    /**
     * @return array{rows:array<int,array>,errors:array<int,string>,total:int}
     */
    public static function parse(string $csv, int $scale = 5): array
    {
        $scale = in_array($scale, [5, 10], true) ? $scale : 5;
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (!mb_check_encoding($csv, 'UTF-8')) {
            return ['rows' => [], 'errors' => ['The file must be saved as UTF-8 (in Excel: Save As, CSV UTF-8)'], 'total' => 0];
        }
        $firstLine = strtok($csv, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : (substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',');
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $header = fgetcsv($fh, 0, $delimiter);
        if (!$header) {
            return ['rows' => [], 'errors' => ['The file is empty'], 'total' => 0];
        }
        $map = [];
        foreach ($header as $i => $h) {
            $h = strtolower(trim((string) $h));
            foreach (self::ALIASES as $field => $names) {
                if (!isset($map[$field]) && in_array($h, $names, true)) {
                    $map[$field] = $i;
                }
            }
        }
        foreach (['rating', 'review_text'] as $need) {
            if (!isset($map[$need])) {
                return ['rows' => [], 'errors' => ['Missing column: ' . $need . ' (found: ' . implode(', ', array_map('trim', $header)) . ')'], 'total' => 0];
            }
        }
        $rows = [];
        $errors = [];
        $line = 1;
        $total = 0;
        while (($r = fgetcsv($fh, 0, $delimiter)) !== false) {
            $line++;
            if ($r === [null] || !array_filter($r, static fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $total++;
            if ($total > self::MAX_ROWS) {
                $errors[] = 'Only the first ' . self::MAX_ROWS . ' rows are read per file';
                break;
            }
            $get = static fn (string $f) => isset($map[$f], $r[$map[$f]]) ? trim((string) $r[$map[$f]]) : '';
            $raw = str_replace(',', '.', $get('rating'));
            if ($raw === '' || !is_numeric($raw)) {
                $errors[] = "Row $line: rating is missing or not a number";
                continue;
            }
            $rating = (int) round(((float) $raw) / $scale * 5);
            if ($rating < 1 || $rating > 5) {
                $errors[] = "Row $line: rating $raw is outside 1-$scale";
                continue;
            }
            $text = mb_substr($get('review_text'), 0, 5000);
            $dateRaw = $get('review_date');
            $ts = $dateRaw !== '' ? strtotime($dateRaw) : false;
            if ($dateRaw !== '' && $ts === false) {
                $errors[] = "Row $line: date \"$dateRaw\" is not understood (use YYYY-MM-DD)";
                continue;
            }
            $rows[] = [
                'rating' => $rating,
                'review_text' => $text,
                'reviewer_name' => mb_substr($get('reviewer_name'), 0, 190),
                'review_date' => date('Y-m-d H:i:s', $ts ?: time()),
                'external_id' => mb_substr($get('external_id'), 0, 120),
                'language' => preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', $get('language')) ? $get('language') : '',
            ];
        }
        fclose($fh);
        return ['rows' => $rows, 'errors' => array_slice($errors, 0, 20), 'total' => $total];
    }

    public static function externalId(int $websiteId, string $source, array $row): string
    {
        $base = $row['external_id'] !== '' ? $row['external_id'] : substr(sha1($source . '|' . $row['reviewer_name'] . '|' . $row['review_date'] . '|' . $row['review_text']), 0, 24);
        return 'w' . $websiteId . ':' . $base;
    }

    /**
     * @return array{imported:int,duplicates:int,errors:array,total:int,preview:bool}
     */
    public static function import(int $userId, int $websiteId, string $source, string $csv, int $scale = 5, bool $dryRun = false, ?Database $db = null): array
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unknown source');
        }
        $parsed = self::parse($csv, $scale);
        $db = $db ?: Database::getInstance();
        $imported = 0;
        $dups = 0;
        foreach ($parsed['rows'] as $row) {
            $ext = self::externalId($websiteId, $source, $row);
            $exists = $db->query('SELECT 1 FROM reviews WHERE source_platform = ? AND external_review_id = ? LIMIT 1', [$source, $ext]);
            if ($exists) {
                $dups++;
                continue;
            }
            if ($dryRun) {
                $imported++;
                continue;
            }
            $sentiment = ReputationManager::ratingSentiment($row['rating']);
            $db->query(
                'INSERT INTO reviews (website_id, user_id, source_platform, external_review_id, reviewer_name, review_text, review_language, rating, review_date, sentiment, sentiment_score, sentiment_confidence, reply_status, webhook_raw_data)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)',
                [$websiteId, $userId, $source, $ext, $row['reviewer_name'] ?: null, $row['review_text'], $row['language'] ?: 'en', $row['rating'], $row['review_date'],
                    $sentiment['label'], $sentiment['score'], $sentiment['confidence'], json_encode(['imported' => true, 'via' => 'csv'])]
            );
            $imported++;
        }
        return ['imported' => $imported, 'duplicates' => $dups, 'errors' => $parsed['errors'], 'total' => $parsed['total'], 'preview' => $dryRun];
    }
}
