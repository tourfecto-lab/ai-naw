<?php
/**
 * Tourfecto - تذكير العملاء برحلات بكرة (كل 15 دقيقة من المنظّم)
 * بيبعت لكل حجز مؤكد بكرة مرة واحدة بس، بعد الساعة اللي صاحب النشاط اختارها.
 */
require_once __DIR__ . '/bootstrap.php';
echo json_encode((new TripReminderService())->runDue()) . "\n";
