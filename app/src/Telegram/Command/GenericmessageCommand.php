<?php

declare(strict_types=1);

namespace App\Telegram\Command;

use App\Service\ChatExportProcessor;
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

    private static ?ChatExportProcessor $processor = null;

    public static function setProcessor(ChatExportProcessor $processor): void
    {
        self::$processor = $processor;
    }

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chatId = $message->getChat()->getId();

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

        return $this->processDocument($chatId, $document);
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