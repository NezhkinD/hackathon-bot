<?php

declare(strict_types=1);

namespace App\Telegram\Command;

use App\Service\RateLimiter;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Команда /start - приветствие пользователя.
 */
class StartCommand extends UserCommand
{
    protected $name = 'start';
    protected $description = 'Начать работу с ботом';
    protected $usage = '/start';
    protected $version = '1.0.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $chatId = $message->getChat()->getId();

        $config = RateLimiter::getConfig();

        $text = sprintf(<<<TEXT
Привет! Я бот для извлечения участников из экспорта чата Telegram.

Как использовать:
1. Откройте нужный чат в Telegram Desktop
2. Экспортируйте историю (Меню → Экспортировать историю чата)
3. Выберите формат JSON или HTML
4. Отправьте мне полученный файл (или несколько файлов)

Я извлеку всех участников и упоминания, а затем:
• Если участников < 50 — пришлю список в чат
• Если участников ≥ 51 — отправлю Excel-файл

Ограничения: до %d файлов за %d сек., макс. размер 20 МБ.

Используйте /help для подробной справки.
TEXT, $config['maxFiles'], $config['windowSeconds']);

        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
