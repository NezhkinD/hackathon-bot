<?php

declare(strict_types=1);

namespace App\Telegram\Command;

use App\Service\ChatExportProcessor;
use App\Service\RateLimiter;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Обработчик всех сообщений (включая документы).
 */
class GenericmessageCommand extends SystemCommand
{
    protected $name = "genericmessage";
    protected $description = "Обработка входящих сообщений";
    protected $version = "1.0.0";

    private const MAX_FILES_PER_GROUP = 10;
    private const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024; // 20 MB - ограничение Telegram Bot API

    private static ?ChatExportProcessor $processor = null;

    /**
     * Хранилище файлов для media groups: [chatId => [mediaGroupId => [files...]]]
     * @var array<int, array<string, array<array{fileId: string, fileName: string, mimeType: string}>>>
     */
    private static array $mediaGroups = [];

    /**
     * Время последнего файла для каждой группы: [chatId => [mediaGroupId => timestamp]]
     * @var array<int, array<string, float>>
     */
    private static array $mediaGroupTimestamps = [];

    /**
     * ID групп, для которых уже отправлено сообщение об ошибке превышения лимита.
     * @var array<string, bool>
     */
    private static array $errorSentForGroup = [];

    public static function setProcessor(ChatExportProcessor $processor): void
    {
        self::$processor = $processor;
    }

    /**
     * Обрабатывает накопленные media groups (вызывается из polling loop).
     */
    public static function processMediaGroups(): void
    {
        $now = microtime(true);
        $timeout = 1.5; // секунды ожидания завершения группы

        foreach (self::$mediaGroups as $chatId => $groups) {
            foreach ($groups as $mediaGroupId => $files) {
                $lastTimestamp = self::$mediaGroupTimestamps[$chatId][$mediaGroupId] ?? 0;

                if ($now - $lastTimestamp >= $timeout) {
                    self::finalizeMediaGroup($chatId, $mediaGroupId);
                }
            }
        }
    }

    private static function finalizeMediaGroup(int $chatId, string|int $mediaGroupId): void
    {
        $files = self::$mediaGroups[$chatId][$mediaGroupId] ?? [];
        unset(
            self::$mediaGroups[$chatId][$mediaGroupId],
            self::$mediaGroupTimestamps[$chatId][$mediaGroupId],
            self::$errorSentForGroup[$mediaGroupId]
        );

        if (empty($files)) {
            return;
        }

        if (count($files) > self::MAX_FILES_PER_GROUP) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => sprintf(
                    "Превышен лимит файлов. Вы отправили %d файлов, максимум — %d.\nПожалуйста, отправьте файлы меньшими группами.",
                    count($files),
                    self::MAX_FILES_PER_GROUP
                ),
            ]);
            return;
        }

        self::processGroupFiles($chatId, $files);
    }

    /**
     * @param array<array{fileId: string, fileName: string, mimeType: string}> $files
     */
    private static function processGroupFiles(int $chatId, array $files): void
    {
        if (self::$processor === null) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => '🔴 Ошибка: процессор не инициализирован.',
            ]);
            return;
        }

        $fileNames = array_map(fn($f) => $f['fileName'], $files);
        Request::sendMessage([
            'chat_id' => $chatId,
            'text' => sprintf("⏳ Обрабатываю %d файлов: " . PHP_EOL . "%s", count($files), implode(PHP_EOL, $fileNames)),
        ]);

        $downloadedFiles = [];

        foreach ($files as $fileData) {
            try {
                $file = Request::getFile(['file_id' => $fileData['fileId']]);
                if (!$file->isOk()) {
                    throw new \RuntimeException("Не удалось получить информацию о файле");
                }

                $filePath = $file->getResult()->getFilePath();
                $botToken = getenv('BOT_TOKEN') ?: $_ENV['BOT_TOKEN'] ?? '';
                $downloadUrl = "https://api.telegram.org/file/bot" . $botToken . "/" . $filePath;

                $content = @file_get_contents($downloadUrl);
                if ($content === false) {
                    throw new \RuntimeException("Не удалось скачать файл");
                }

                $downloadedFiles[] = [
                    'content' => $content,
                    'fileName' => $fileData['fileName'],
                ];
            } catch (\Throwable $e) {
                Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Ошибка загрузки файла {$fileData['fileName']}: " . $e->getMessage(),
                ]);
            }
        }

        if (!empty($downloadedFiles)) {
            try {
                self::$processor->processMultiple($chatId, $downloadedFiles);
            } catch (\Throwable $e) {
                Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Ошибка обработки: " . $e->getMessage(),
                ]);
            }
        }
    }

    private static function formatFileSize(int $bytes): string
    {
        $mb = $bytes / (1024 * 1024);
        return number_format($mb, 1, '.', '') . ' MB';
    }

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chatId = $message->getChat()->getId();

        // Получаем userId для rate limiting
        $user = $message->getFrom();
        $userId = $user?->getId() ?? $chatId;

        $document = $message->getDocument();
        if ($document === null) {
            $text = $message->getText();
            if ($text !== null && !str_starts_with($text, "/")) {
                return Request::sendMessage([
                    "chat_id" => $chatId,
                    "text" => "Отправьте мне файл экспорта чата (JSON или HTML).\nИспользуйте /help для справки.",
                ]);
            }
            return Request::emptyResponse();
        }

        // Проверка rate limit
        $rateLimitResult = RateLimiter::checkAndRecord($userId);
        if (!$rateLimitResult['allowed']) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => sprintf(
                    "Превышен лимит загрузки файлов.\n\n" .
                    "Вы можете загружать до %d файлов за %d секунд.\n" .
                    "Подождите %d сек. и попробуйте снова.\n\n" .
                    "Это ограничение защищает бот от перегрузки.",
                    $rateLimitResult['limit'],
                    $rateLimitResult['window'],
                    $rateLimitResult['retryAfter']
                ),
            ]);
        }

        $mediaGroupId = $message->getMediaGroupId();

        if ($mediaGroupId !== null) {
            return $this->handleMediaGroupDocument($chatId, $mediaGroupId, $document);
        }

        return $this->processDocument($chatId, $document);
    }

    private function handleMediaGroupDocument(
        int                                    $chatId,
        string|int                             $mediaGroupId,
        \Longman\TelegramBot\Entities\Document $document
    ): ServerResponse
    {
        $fileName = $document->getFileName() ?? "unknown";
        $mimeType = $document->getMimeType() ?? "";
        $fileId = $document->getFileId();

        $isJson = str_ends_with(strtolower($fileName), ".json") || $mimeType === "application/json";
        $isHtml = str_ends_with(strtolower($fileName), ".html") || str_contains($mimeType, "html");

        if (!$isJson && !$isHtml) {
            return Request::sendMessage([
                "chat_id" => $chatId,
                "text" => "Неподдерживаемый формат файла: {$fileName}\n\nПоддерживаемые форматы: JSON, HTML",
            ]);
        }

        $fileSize = $document->getFileSize() ?? 0;
        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            return Request::sendMessage([
                "chat_id" => $chatId,
                "text" => sprintf(
                    "Файл %s слишком большой (%s).\n\nTelegram позволяет ботам скачивать файлы размером до 20 MB.\n\nПожалуйста:\n- Разделите чат на несколько экспортов\n- Или экспортируйте меньший период времени",
                    $fileName,
                    self::formatFileSize($fileSize)
                ),
            ]);
        }

        // Инициализируем хранилище для чата если нужно
        if (!isset(self::$mediaGroups[$chatId])) {
            self::$mediaGroups[$chatId] = [];
            self::$mediaGroupTimestamps[$chatId] = [];
        }

        // Инициализируем группу если нужно
        if (!isset(self::$mediaGroups[$chatId][$mediaGroupId])) {
            self::$mediaGroups[$chatId][$mediaGroupId] = [];
        }

        // Проверяем лимит
        $currentCount = count(self::$mediaGroups[$chatId][$mediaGroupId]);
        if ($currentCount >= self::MAX_FILES_PER_GROUP) {
            // Отправляем сообщение об ошибке только один раз для группы
            if (!isset(self::$errorSentForGroup[$mediaGroupId])) {
                self::$errorSentForGroup[$mediaGroupId] = true;
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => sprintf(
                        "Превышен лимит файлов. Максимум — %d файлов за один раз.\nОстальные файлы из этой группы будут проигнорированы.",
                        self::MAX_FILES_PER_GROUP
                    ),
                ]);
            }
            return Request::emptyResponse();
        }

        // Добавляем файл в группу
        self::$mediaGroups[$chatId][$mediaGroupId][] = [
            'fileId' => $fileId,
            'fileName' => $fileName,
            'mimeType' => $mimeType,
        ];
        self::$mediaGroupTimestamps[$chatId][$mediaGroupId] = microtime(true);

        return Request::emptyResponse();
    }

    private function processDocument(int $chatId, \Longman\TelegramBot\Entities\Document $document): ServerResponse
    {
        $fileName = $document->getFileName() ?? "unknown";
        $mimeType = $document->getMimeType() ?? "";
        $fileId = $document->getFileId();

        $isJson = str_ends_with(strtolower($fileName), ".json") || $mimeType === "application/json";
        $isHtml = str_ends_with(strtolower($fileName), ".html") || str_contains($mimeType, "html");

        if (!$isJson && !$isHtml) {
            return Request::sendMessage([
                "chat_id" => $chatId,
                "text" => "Неподдерживаемый формат файла: {$fileName}\n\nПоддерживаемые форматы: JSON, HTML\nИспользуйте /help для справки.",
            ]);
        }

        $fileSize = $document->getFileSize() ?? 0;
        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            return Request::sendMessage([
                "chat_id" => $chatId,
                "text" => sprintf(
                    "Файл слишком большой (%s).\n\nTelegram позволяет ботам скачивать файлы размером до 20 MB.\n\nПожалуйста:\n- Разделите чат на несколько экспортов\n- Или экспортируйте меньший период времени",
                    self::formatFileSize($fileSize)
                ),
            ]);
        }

        Request::sendMessage([
            "chat_id" => $chatId,
            "text" => "Обрабатываю файл: {$fileName}...",
        ]);

        try {
            $file = Request::getFile(["file_id" => $fileId]);
            if (!$file->isOk()) {
                throw new \RuntimeException("Не удалось получить информацию о файле");
            }

            $filePath = $file->getResult()->getFilePath();
            $downloadUrl = "https://api.telegram.org/file/bot" . $this->telegram->getApiKey() . "/" . $filePath;

            $content = @file_get_contents($downloadUrl);
            if ($content === false) {
                throw new \RuntimeException("Не удалось скачать файл");
            }

            if (self::$processor === null) {
                throw new \RuntimeException("Processor не инициализирован");
            }

            self::$processor->process($chatId, $content, $fileName);

            return Request::emptyResponse();

        } catch (\Throwable $e) {
            return Request::sendMessage([
                "chat_id" => $chatId,
                "text" => "Ошибка обработки файла: " . $e->getMessage(),
            ]);
        }
    }
}
