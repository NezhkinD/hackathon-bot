<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;

/**
 * Обёртка над longman/telegram-bot для инициализации и управления ботом.
 */
class BotService
{
    private ?Telegram $telegram = null;

    public function __construct(
        private readonly string $botToken,
        private readonly string $botUsername,
        private readonly string $commandsPath,
    ) {}

    /**
     * Инициализирует Telegram бота.
     *
     * @throws TelegramException
     */
    public function initialize(): Telegram
    {
        if ($this->telegram === null) {
            $this->telegram = new Telegram($this->botToken, $this->botUsername);
            $this->telegram->addCommandsPath($this->commandsPath);
            $this->telegram->useGetUpdatesWithoutDatabase();
        }

        return $this->telegram;
    }

    /**
     * Возвращает инициализированный экземпляр Telegram.
     *
     * @throws TelegramException
     */
    public function getTelegram(): Telegram
    {
        return $this->initialize();
    }

    /**
     * Обрабатывает обновления через long polling.
     *
     * @throws TelegramException
     */
    public function handleUpdates(): void
    {
        $telegram = $this->getTelegram();
        $telegram->handleGetUpdates();
    }
}
