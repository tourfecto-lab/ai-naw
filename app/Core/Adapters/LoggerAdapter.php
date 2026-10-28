<?php

/**
 * Tourfecto - Logger Adapter
 * @version 1.0.0
 *
 * Adapter Pattern: بيلف كلاس Logger الثابت (static) الموجود فعلاً خلف
 * LoggerInterface عشان يقدر يتحقن (DI) في أي Repository/Service جديد
 * بدل ما يعتمد على استدعاء ثابت مباشر (اللي بيصعّب الاختبار/الاستبدال).
 * كلاس Logger الأصلي نفسه من غير أي تعديل.
 */
class LoggerAdapter implements LoggerInterface
{
    public function emergency(string $message, array $context = []): void
    {
        Logger::emergency($message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        Logger::log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        Logger::critical($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        Logger::error($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        Logger::warning($message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        Logger::log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        Logger::info($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        Logger::debug($message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        Logger::log($level, $message, $context);
    }
}
