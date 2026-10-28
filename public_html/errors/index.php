<?php

/**
 * Tourfecto - Index (نسخة مبسطة)
 */

// عرض جميع الأخطاء
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// ============================================
// 1. اختبار التشغيل الأساسي
// ============================================
echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Tourfecto</title>
    <style>
        body { font-family: Arial; text-align: center; padding: 50px; background: #0d1117; color: #e6edf3; }
        .box { max-width: 600px; margin: 0 auto; background: #161b22; padding: 40px; border-radius: 12px; border: 1px solid #30363d; }
        h1 { color: #f0883e; }
        .success { color: #28a745; font-size: 20px; }
        .error { color: #dc3545; }
        .info { color: #58a6ff; }
        .check { margin: 10px 0; padding: 10px; background: #0d1117; border-radius: 6px; text-align: left; }
    </style>
</head>
<body>
    <div class='box'>
        <h1>🚀 Tourfecto</h1>
        <p class='success'>✅ الموقع يعمل بشكل صحيح!</p>
        <hr style='border-color: #30363d;'>
        <div class='check'>
            <p>📅 التاريخ: " . date('Y-m-d H:i:s') . "</p>
            <p>🐘 PHP: " . phpversion() . "</p>
            <p>📁 المسار: " . __DIR__ . "</p>
        </div>
        <hr style='border-color: #30363d;'>
        <p style='color: #8b949e; font-size: 14px;'>جاري تحميل التطبيق...</p>
    </div>
</body>
</html>";
