<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\DTO\Participant;
use App\DTO\ProcessingResult;
use Symfony\Component\DomCrawler\Crawler;

class HtmlExportParser implements ParserInterface
{
    public function supports(string $content): bool
    {
        return str_contains($content, 'class="message')
            || str_contains($content, 'tgme_widget_message')
            || str_contains($content, 'history');
    }

    public function parse(string $content): ProcessingResult
    {
        $crawler = new Crawler($content);

        $participants = [];
        $mentions = [];
        $channels = [];
        $forwardedAuthors = [];

        $crawler->filter('.message, .tgme_widget_message')->each(
            function (Crawler $node) use (&$participants, &$mentions, &$channels, &$forwardedAuthors) {
                $this->processMessage($node, $participants, $mentions, $channels, $forwardedAuthors);
            }
        );

        return new ProcessingResult($participants, $mentions, $channels, $forwardedAuthors);
    }

    private function processMessage(
        Crawler $node,
        array &$participants,
        array &$mentions,
        array &$channels,
        array &$forwardedAuthors
    ): void {
        // Извлекаем автора пересланного сообщения (если есть)
        $fwdNode = $node->filter('.forwarded .from_name, .tgme_widget_message_forwarded_from_name');
        if ($fwdNode->count() > 0) {
            $fwdName = $this->cleanName(trim($fwdNode->first()->text()));
            if (!empty($fwdName) && $fwdName !== 'Deleted Account') {
                $id = 'fwd_' . md5($fwdName);
                $forwardedAuthors[$id] = new Participant(id: $id, name: $fwdName, isForwarded: true);
            }
        }

        // Извлекаем автора сообщения (исключая тех, кто в .forwarded)
        // Берём только .from_name который НЕ внутри .forwarded
        $fromNode = $node->filter('.body > .from_name, .tgme_widget_message_owner_name');
        if ($fromNode->count() > 0) {
            $name = $this->cleanName(trim($fromNode->first()->text()));
            if (!empty($name) && $name !== 'Deleted Account') {
                $id = md5($name);
                $participants[$id] = new Participant(id: $id, name: $name);
            }
        }

        $textNode = $node->filter('.text, .tgme_widget_message_text');
        if ($textNode->count() > 0) {
            $text = $textNode->text();
            preg_match_all('/@([a-zA-Z0-9_]{5,41})/', $text, $matches);
            foreach ($matches[1] as $username) {
                $mentions[$username] = $username;
            }
        }

        $node->filter('a[href*="t.me/"]')->each(function (Crawler $link) use (&$channels) {
            $href = $link->attr('href') ?? '';
            if (preg_match('~t\.me/([a-zA-Z0-9_]+)~', $href, $m)) {
                $ch = $m[1];
                if (!in_array($ch, ['joinchat', 'share', 'addstickers'])) {
                    $channels[$ch] = $ch;
                }
            }
        });
    }

    /**
     * Очищает имя от даты и времени (которые могут быть в span внутри from_name для forwarded).
     */
    private function cleanName(string $name): string
    {
        // Удаляем дату/время вида " 07.12.2025 20:03:29" из конца имени
        return preg_replace('/\s+\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2}$/', '', $name) ?? $name;
    }
}
