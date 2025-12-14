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

        $crawler->filter('.message, .tgme_widget_message')->each(
            function (Crawler $node) use (&$participants, &$mentions, &$channels) {
                $this->processMessage($node, $participants, $mentions, $channels);
            }
        );

        return new ProcessingResult($participants, $mentions, $channels);
    }

    private function processMessage(
        Crawler $node,
        array &$participants,
        array &$mentions,
        array &$channels
    ): void {
        $fromNode = $node->filter('.from_name, .tgme_widget_message_owner_name');
        if ($fromNode->count() > 0) {
            $name = trim($fromNode->text());
            if (!empty($name) && $name !== 'Deleted Account') {
                $id = md5($name);
                $participants[$id] = new Participant(id: $id, name: $name);
            }
        }

        $fwdNode = $node->filter('.forwarded.from_name, .tgme_widget_message_forwarded_from_name');
        if ($fwdNode->count() > 0) {
            $fwdName = trim($fwdNode->text());
            if (!empty($fwdName) && $fwdName !== 'Deleted Account') {
                $id = 'fwd_' . md5($fwdName);
                $participants[$id] = new Participant(id: $id, name: $fwdName, isForwarded: true);
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
}
