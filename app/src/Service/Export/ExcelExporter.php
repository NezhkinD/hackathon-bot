<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\DTO\ProcessingResult;
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

        // Вкладка 1: Участники
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Участники');
        $this->fillParticipantsSheet($sheet, $result);

        // Вкладка 2: Упоминания
        $mentionsSheet = $spreadsheet->createSheet();
        $mentionsSheet->setTitle('Упоминания');
        $this->fillMentionsSheet($mentionsSheet, $result);

        // Вкладка 3: Каналы
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
            'Пересланное сообщение',
        ];

        $sheet->fromArray($headers, null, 'A1');
        
        // Стилизация заголовков
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);

        $row = 2;
        $exportDate = (new \DateTime())->format('d.m.Y H:i');

        foreach ($result->getParticipants() as $participant) {
            $sheet->setCellValue("A{$row}", $exportDate);
            $sheet->setCellValue("B{$row}", $participant->username ? '@' . $participant->username : '-');
            $sheet->setCellValue("C{$row}", $participant->name ?? '-');
            $sheet->setCellValue("D{$row}", $participant->bio ?? '-');
            $sheet->setCellValue("E{$row}", $participant->registrationDate ?? '-');
            $sheet->setCellValue("F{$row}", $participant->hasChannel ? 'Да' : 'Нет');
            $sheet->setCellValue("G{$row}", $participant->isForwarded ? 'Да' : 'Нет');
            $row++;
        }

        // Автоширина колонок
        foreach (range('A', 'G') as $col) {
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
            $sheet->setCellValue("A{$row}", $channel);
            $row++;
        }

        $sheet->getColumnDimension('A')->setAutoSize(true);
    }
}
