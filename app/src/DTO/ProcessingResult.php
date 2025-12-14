<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Результат обработки экспорта чата.
 */
readonly class ProcessingResult
{
    /**
     * @param array<string, Participant> $participants Участники (ключ - id)
     * @param array<string, string> $mentions Упоминания (ключ - username)
     * @param array<string, string> $channels Каналы (ключ - id или username)
     * @param array<string, Participant> $forwardedAuthors Авторы пересланных сообщений (не участники чата)
     */
    public function __construct(
        private array $participants = [],
        private array $mentions = [],
        private array $channels = [],
        private array $forwardedAuthors = [],
    ) {}

    /**
     * @return array<string, Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * @return array<string, string>
     */
    public function getMentions(): array
    {
        return $this->mentions;
    }

    /**
     * @return array<string, string>
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * @return array<string, Participant>
     */
    public function getForwardedAuthors(): array
    {
        return $this->forwardedAuthors;
    }

    public function getParticipantsCount(): int
    {
        return count($this->participants);
    }

    public function getMentionsCount(): int
    {
        return count($this->mentions);
    }

    public function getChannelsCount(): int
    {
        return count($this->channels);
    }

    public function getForwardedAuthorsCount(): int
    {
        return count($this->forwardedAuthors);
    }

    public function getTotalCount(): int
    {
        return $this->getParticipantsCount();
    }

    /**
     * Объединяет результаты с другим ProcessingResult.
     */
    public function merge(ProcessingResult $other): ProcessingResult
    {
        return new ProcessingResult(
            participants: array_merge($this->participants, $other->participants),
            mentions: array_merge($this->mentions, $other->mentions),
            channels: array_merge($this->channels, $other->channels),
            forwardedAuthors: array_merge($this->forwardedAuthors, $other->forwardedAuthors),
        );
    }
}
