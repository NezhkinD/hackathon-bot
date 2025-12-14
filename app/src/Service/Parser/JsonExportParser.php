<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\DTO\Participant;
use App\DTO\ProcessingResult;

/**
 * Парсер JSON экспорта Telegram Desktop.
 */
class JsonExportParser implements ParserInterface
{
    public function supports(string $content): bool
    {
        $data = json_decode($content, true);
        return $data !== null && isset($data["messages"]);
    }

    public function parse(string $content): ProcessingResult
    {
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $participants = [];
        $mentions = [];
        $channels = [];
        $forwardedAuthors = [];

        foreach ($data["messages"] ?? [] as $message) {
            if ($this->isValidParticipant($message)) {
                $participant = $this->extractParticipant($message);
                if ($participant !== null) {
                    $participants[$participant->id] = $participant;
                }
            }

            if (isset($message["forwarded_from"])) {
                $forwardedParticipant = $this->extractForwardedParticipant($message);
                if ($forwardedParticipant !== null) {
                    $forwardedAuthors[$forwardedParticipant->id] = $forwardedParticipant;
                }
            }

            $this->extractMentions($message, $mentions);
            $this->extractChannels($message, $channels);
        }

        return new ProcessingResult($participants, $mentions, $channels, $forwardedAuthors);
    }

    private function isValidParticipant(array $message): bool
    {
        $type = $message["type"] ?? "";
        if ($type !== "message") {
            return false;
        }

        $from = $message["from"] ?? "";
        if (empty($from) || $from === "Deleted Account") {
            return false;
        }

        return true;
    }

    private function extractParticipant(array $message): ?Participant
    {
        $fromId = $message["from_id"] ?? null;
        $from = $message["from"] ?? null;

        if ($fromId === null && $from === null) {
            return null;
        }

        $id = $this->normalizeId($fromId ?? md5($from));

        return new Participant(
            id: $id,
            name: $from,
            username: null,
        );
    }

    private function extractForwardedParticipant(array $message): ?Participant
    {
        $forwardedFrom = $message["forwarded_from"] ?? null;
        if ($forwardedFrom === null || $forwardedFrom === "Deleted Account") {
            return null;
        }

        $id = "fwd_" . md5($forwardedFrom);

        return new Participant(
            id: $id,
            name: $forwardedFrom,
            isForwarded: true,
        );
    }

    private function extractMentions(array $message, array &$mentions): void
    {
        foreach ($message["text_entities"] ?? [] as $entity) {
            if (($entity["type"] ?? "") === "mention") {
                $username = ltrim($entity["text"] ?? "", "@");
                if (!empty($username)) {
                    $mentions[$username] = $username;
                }
            }
        }

        $text = $message["text"] ?? "";
        if (is_string($text)) {
            preg_match_all("/@([a-zA-Z0-9_]{5,32})/", $text, $matches);
            foreach ($matches[1] as $username) {
                $mentions[$username] = $username;
            }
        }
    }

    private function extractChannels(array $message, array &$channels): void
    {
        $fromId = $message["from_id"] ?? "";
        if (is_string($fromId) && str_starts_with($fromId, "channel")) {
            $channelId = $this->normalizeId($fromId);
            $channelName = $message["from"] ?? $channelId;
            $channels[$channelId] = $channelName;
        }
    }

    private function normalizeId(string $fromId): string
    {
        return preg_replace("/^(user|channel)/", "", $fromId) ?? $fromId;
    }
}