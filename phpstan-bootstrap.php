<?php

/**
 * PHPStan Bootstrap - يعرّف الثوابت التي تُعرَّف وقت التشغيل من ملفات
 * الإعدادات، حتى لا يبلّغ التحليل الساكن عن "Constant not found".
 */

define('TOURFECTO_ROOT', __DIR__);
define('TOURFECTO_STORAGE', __DIR__ . '/storage');

require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__)->load();
}

require_once __DIR__ . '/app/Helpers/functions.php';
require_once __DIR__ . '/app/Config/app.php';
require_once __DIR__ . '/app/Config/constants.php';
require_once __DIR__ . '/app/Config/database.php';
require_once __DIR__ . '/app/Config/encryption.php';
