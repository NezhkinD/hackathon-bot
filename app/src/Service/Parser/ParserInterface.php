<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\DTO\ProcessingResult;

/**
 * Интерфейс парсера экспорта чата.
 */
interface ParserInterface
{
    /**
     * Проверяет, поддерживает ли парсер данный контент.
     */
    public function supports(string $content): bool;

    /**
     * Парсит контент и возвращает результат.
     */
    public function parse(string $content): ProcessingResult;
}
