<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Exception\ParsingException;

/**
 * Фабрика для выбора подходящего парсера.
 */
class ParserFactory
{
    /**
     * @param iterable<ParserInterface> $parsers
     */
    public function __construct(
        private readonly iterable $parsers,
    ) {}

    /**
     * Возвращает парсер, поддерживающий данный контент.
     *
     * @throws ParsingException если подходящий парсер не найден
     */
    public function getParser(string $content): ParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($content)) {
                return $parser;
            }
        }

        throw new ParsingException('Не удалось определить формат файла. Поддерживаются JSON и HTML экспорты Telegram.');
    }
}
