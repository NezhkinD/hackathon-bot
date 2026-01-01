<?php

declare(strict_types=1);

namespace App\Service;

/**
 * In-memory rate limiter для ограничения загрузки файлов.
 *
 * Использует sliding window algorithm:
 * - Храним timestamps загрузок для каждого пользователя
 * - При проверке удаляем устаревшие записи (старше window)
 * - Если количество записей >= limit, отклоняем запрос
 */
class RateLimiter
{
    private const DEFAULT_MAX_FILES = 5;
    private const DEFAULT_WINDOW_SECONDS = 30;

    /**
     * @var array<int, array<float>> [userId => [timestamp1, timestamp2, ...]]
     */
    private static array $userFileUploads = [];

    private static int $maxFiles = self::DEFAULT_MAX_FILES;
    private static int $windowSeconds = self::DEFAULT_WINDOW_SECONDS;

    /**
     * Конфигурирует rate limiter.
     */
    public static function configure(int $maxFiles, int $windowSeconds): void
    {
        self::$maxFiles = $maxFiles;
        self::$windowSeconds = $windowSeconds;
    }

    /**
     * Проверяет, разрешена ли загрузка файла для пользователя.
     * Если разрешена - автоматически регистрирует загрузку.
     *
     * @return array{allowed: bool, retryAfter: int|null, currentCount: int, limit: int, window: int}
     */
    public static function checkAndRecord(int $userId): array
    {
        $now = microtime(true);
        $windowStart = $now - self::$windowSeconds;

        // Очищаем устаревшие записи для пользователя
        if (isset(self::$userFileUploads[$userId])) {
            self::$userFileUploads[$userId] = array_values(
                array_filter(
                    self::$userFileUploads[$userId],
                    fn(float $timestamp) => $timestamp > $windowStart
                )
            );
        } else {
            self::$userFileUploads[$userId] = [];
        }

        $currentCount = count(self::$userFileUploads[$userId]);

        $baseResult = [
            'limit' => self::$maxFiles,
            'window' => self::$windowSeconds,
        ];

        // Проверяем лимит
        if ($currentCount >= self::$maxFiles) {
            $oldestTimestamp = min(self::$userFileUploads[$userId]);
            $retryAfter = (int) ceil($oldestTimestamp + self::$windowSeconds - $now);

            return array_merge($baseResult, [
                'allowed' => false,
                'retryAfter' => max(1, $retryAfter),
                'currentCount' => $currentCount,
            ]);
        }

        // Регистрируем загрузку
        self::$userFileUploads[$userId][] = $now;

        return array_merge($baseResult, [
            'allowed' => true,
            'retryAfter' => null,
            'currentCount' => $currentCount + 1,
        ]);
    }

    /**
     * Периодическая очистка для предотвращения memory leak.
     */
    public static function cleanup(): void
    {
        $now = microtime(true);
        $windowStart = $now - self::$windowSeconds;

        foreach (self::$userFileUploads as $userId => $timestamps) {
            $fresh = array_filter($timestamps, fn($t) => $t > $windowStart);

            if (empty($fresh)) {
                unset(self::$userFileUploads[$userId]);
            } else {
                self::$userFileUploads[$userId] = array_values($fresh);
            }
        }
    }

    /**
     * Возвращает текущие настройки лимитов.
     *
     * @return array{maxFiles: int, windowSeconds: int}
     */
    public static function getConfig(): array
    {
        return [
            'maxFiles' => self::$maxFiles,
            'windowSeconds' => self::$windowSeconds,
        ];
    }

    /**
     * Сбрасывает все данные (для тестов).
     */
    public static function reset(): void
    {
        self::$userFileUploads = [];
        self::$maxFiles = self::DEFAULT_MAX_FILES;
        self::$windowSeconds = self::DEFAULT_WINDOW_SECONDS;
    }
}
