<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\DTO\ProcessingResult;

/**
 * Форматирование результатов для текстового вывода.
 */
class TextFormatter
{
    /**
     * Форматирует результат для отправки в чат.
     */
    public function format(ProcessingResult $result): string
    {
        $lines = [];

        // Заголовок
        $participantsCount = $result->getParticipantsCount();
        $forwardedCount = $result->getForwardedAuthorsCount();
        $mentionsCount = $result->getMentionsCount();
        $channelsCount = $result->getChannelsCount();

        $lines[] = "📊 Результаты анализа экспорта чата\n";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        // Участники
        if ($participantsCount > 0) {
            $lines[] = "\n👥 Участники чата ({$participantsCount}):";
            foreach ($result->getParticipants() as $participant) {
                $displayName = $participant->getDisplayName();
                $lines[] = "• {$displayName}";
            }
        }

        // Авторы пересланных сообщений (не участники чата)
        if ($forwardedCount > 0) {
            $lines[] = "\n📩 Авторы пересланных сообщений ({$forwardedCount}):";
            foreach ($result->getForwardedAuthors() as $author) {
                $displayName = $author->getDisplayName();
                $lines[] = "• {$displayName}";
            }
        }

        // Упоминания
        if ($mentionsCount > 0) {
            $lines[] = "\n📢 Упоминания ({$mentionsCount}):";
            foreach ($result->getMentions() as $username) {
                $lines[] = "• @{$username}";
            }
        }

        // Каналы
        if ($channelsCount > 0) {
            $lines[] = "\n📺 Каналы ({$channelsCount}):";
            foreach ($result->getChannels() as $channel) {
                $lines[] = "• {$channel}";
            }
        }

        // Итог
        $lines[] = "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "Всего: {$participantsCount} участников, {$forwardedCount} авторов пересланных";

        return implode("\n", $lines);
    }
}
