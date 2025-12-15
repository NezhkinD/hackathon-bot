<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\DTO\ProcessingResult;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Генератор Excel файла с результатами.
 */
class ExcelExporter
{
    /**
     * Генерирует Excel файл и возвращает его содержимое.
     */
    public function export(ProcessingResult $result): string
    {
        $spreadsheet = new Spreadsheet();

        // Вкладка 1: Участники чата
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Участники');
        $this->fillParticipantsSheet($sheet, $result);

        // Вкладка 2: Авторы пересланных сообщений
        $forwardedSheet = $spreadsheet->createSheet();
        $forwardedSheet->setTitle('Пересланные');
        $this->fillForwardedAuthorsSheet($forwardedSheet, $result);

        // Вкладка 3: Упоминания
        $mentionsSheet = $spreadsheet->createSheet();
        $mentionsSheet->setTitle('Упоминания');
        $this->fillMentionsSheet($mentionsSheet, $result);

        // Вкладка 4: Каналы
        $channelsSheet = $spreadsheet->createSheet();
        $channelsSheet->setTitle('Каналы');
        $this->fillChannelsSheet($channelsSheet, $result);

        // Запись в память (не на диск!)
        $writer = new Xlsx($spreadsheet);

        $tempStream = fopen('php://temp', 'r+');
        $writer->save($tempStream);
        rewind($tempStream);
        $content = stream_get_contents($tempStream);
        fclose($tempStream);

        return $content;
    }

    private function fillParticipantsSheet(Worksheet $sheet, ProcessingResult $result): void
    {
        // Заголовки согласно ТЗ
        $headers = [
            'Дата экспорта',
            'Username',
            'Имя и фамилия',
            'Описание',
            'Дата регистрации',
            'Наличие канала в профиле',
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Стилизация заголовков
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $row = 2;
        $exportDate = (new \DateTime())->format('d.m.Y H:i');

        foreach ($result->getParticipants() as $participant) {
            $sheet->setCellValue("A{$row}", $exportDate);
            $sheet->setCellValue("B{$row}", $participant->username ? '@' . $participant->username : '-');
            $sheet->setCellValueExplicit("C{$row}", $participant->name ?? '-', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", $participant->bio ?? '-', DataType::TYPE_STRING);
            $sheet->setCellValue("E{$row}", $participant->registrationDate ?? '-');
            $sheet->setCellValue("F{$row}", $participant->hasChannel ? 'Да' : 'Нет');
            $row++;
        }

        // Автоширина колонок
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function fillForwardedAuthorsSheet(Worksheet $sheet, ProcessingResult $result): void
    {
        $headers = [
            'Дата экспорта',
            'Username',
            'Имя и фамилия',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);

        $row = 2;
        $exportDate = (new \DateTime())->format('d.m.Y H:i');

        foreach ($result->getForwardedAuthors() as $author) {
            $sheet->setCellValue("A{$row}", $exportDate);
            $sheet->setCellValue("B{$row}", $author->username ? '@' . $author->username : '-');
            $sheet->setCellValueExplicit("C{$row}", $author->name ?? '-', DataType::TYPE_STRING);
            $row++;
        }

        foreach (range('A', 'C') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function fillMentionsSheet(Worksheet $sheet, ProcessingResult $result): void
    {
        $headers = ['Username'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $row = 2;
        foreach ($result->getMentions() as $username) {
            $sheet->setCellValue("A{$row}", '@' . $username);
            $row++;
        }

        $sheet->getColumnDimension('A')->setAutoSize(true);
    }

    private function fillChannelsSheet(Worksheet $sheet, ProcessingResult $result): void
    {
        $headers = ['Канал'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $row = 2;
        foreach ($result->getChannels() as $channel) {
            $sheet->setCellValueExplicit("A{$row}", $channel, DataType::TYPE_STRING);
            $row++;
        }

        $sheet->getColumnDimension('A')->setAutoSize(true);
    }
}
