<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ProcessingResult;
use App\Service\Export\ExcelExporter;
use App\Service\Export\TextFormatter;
use App\Service\Parser\ParserFactory;
use Longman\TelegramBot\Request;

/**
 * Главный координатор обработки экспорта чата.
 */
class ChatExportProcessor
{
    private const THRESHOLD_FOR_EXCEL = 50;

    public function __construct(
        private readonly ParserFactory $parserFactory,
        private readonly ExcelExporter $excelExporter,
        private readonly TextFormatter $textFormatter,
        private readonly string $botToken,
    ) {}

    /**
     * Обрабатывает контент файла и отправляет результат в чат.
     */
    public function process(int $chatId, string $content, string $fileName): void
    {
        // Парсим контент
        $parser = $this->parserFactory->getParser($content);
        $result = $parser->parse($content);

        // Проверяем, есть ли результаты
        if ($result->getParticipantsCount() === 0) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "⚠️ В файле {$fileName} не найдено участников.\n\nУбедитесь, что это корректный экспорт чата Telegram.",
            ]);
            return;
        }

        // Формируем и отправляем результат
        if ($result->getTotalCount() < self::THRESHOLD_FOR_EXCEL) {
            $this->sendTextResult($chatId, $result);
        } else {
            $this->sendExcelResult($chatId, $result, $fileName);
        }
    }

    /**
     * Обрабатывает несколько файлов и объединяет результаты.
     *
     * @param array<array{content: string, fileName: string}> $files
     */
    public function processMultiple(int $chatId, array $files): void
    {
        $combinedResult = new ProcessingResult();

        foreach ($files as $file) {
            try {
                $parser = $this->parserFactory->getParser($file['content']);
                $result = $parser->parse($file['content']);
                $combinedResult = $combinedResult->merge($result);
            } catch (\Throwable $e) {
                Request::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "⚠️ Ошибка обработки файла {$file['fileName']}: {$e->getMessage()}",
                ]);
            }
        }

        if ($combinedResult->getParticipantsCount() === 0) {
            Request::sendMessage([
                'chat_id' => $chatId,
                'text' => "⚠️ Не найдено участников ни в одном из файлов.",
            ]);
            return;
        }

        if ($combinedResult->getTotalCount() < self::THRESHOLD_FOR_EXCEL) {
            $this->sendTextResult($chatId, $combinedResult);
        } else {
            $this->sendExcelResult($chatId, $combinedResult, 'combined_export');
        }
    }

    private function sendTextResult(int $chatId, ProcessingResult $result): void
    {
        $text = $this->textFormatter->format($result);

        Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    private function sendExcelResult(int $chatId, ProcessingResult $result, string $fileName): void
    {
        $excelContent = $this->excelExporter->export($result);

        // Генерируем имя файла
        $outputFileName = 'participants_' . date('Y-m-d_H-i-s') . '.xlsx';

        // Создаём временный файл для отправки (с расширением .xlsx)
        $tempFile = tempnam(sys_get_temp_dir(), 'tg_export_');
        $tempFileXlsx = $tempFile . '.xlsx';
        rename($tempFile, $tempFileXlsx);
        file_put_contents($tempFileXlsx, $excelContent);

        try {
            Request::sendDocument([
                'chat_id' => $chatId,
                'document' => Request::encodeFile($tempFileXlsx),
                'filename' => $outputFileName,
                'caption' => sprintf(
                    "📊 Результат анализа\n\nУчастников: %d\nУпоминаний: %d\nКаналов: %d",
                    $result->getParticipantsCount(),
                    $result->getMentionsCount(),
                    $result->getChannelsCount()
                ),
            ]);
        } finally {
            // Удаляем временный файл
            @unlink($tempFileXlsx);
        }
    }
}
