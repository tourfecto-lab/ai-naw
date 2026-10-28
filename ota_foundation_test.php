<?php
require_once __DIR__ . '/../app/Services/Integrations/OTA/OtaPlatformCatalog.php';
require_once __DIR__ . '/../app/Services/Integrations/OTA/OtaIdentity.php';
function check(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } echo "PASS: {$message}\n"; }
check(OtaPlatformCatalog::supports('Booking.com', 'reservations'), 'platform aliases normalize');
check(OtaPlatformCatalog::supports('GetYourGuide', 'booking'), 'GetYourGuide booking capability catalogued');
check(!OtaPlatformCatalog::supports('Tripadvisor', 'review_replies'), 'unsupported Tripadvisor replies are not promised');
$off = OtaPlatformCatalog::effectiveCapabilities('expedia_group', ['reviews','messaging'], false);
check(($off['reviews'] ?? true) === false && ($off['messaging'] ?? true) === false, 'capabilities stay disabled until verified');
$a = OtaIdentity::externalKey(7, 'Booking.com', 'acct-1', 'booking', 'ABC-123');
$b = OtaIdentity::externalKey(7, 'booking_com', 'acct-1', 'booking', 'ABC-123');
check($a === $b, 'stable key is idempotent across platform aliases');
$normalized = OtaIdentity::normalizeBooking(['reservation_id'=>'ABC-123','status'=>'confirmed','amount'=>'19.95','currency'=>'USD','check_in'=>'2026-11-01'], 'Booking.com', 'acct-1');
check($normalized['external_reference'] === 'ABC-123' && $normalized['total_amount'] === 19.95, 'booking normalization');
$thrown = false; try { OtaIdentity::normalizeBooking([], 'Booking.com', 'acct-1'); } catch (InvalidArgumentException $e) { $thrown = true; }
check($thrown, 'missing external booking reference rejected');
