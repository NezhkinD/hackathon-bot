<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Представляет участника чата.
 */
readonly class Participant
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $username = null,
        public ?string $bio = null,
        public ?string $registrationDate = null,
        public bool $hasChannel = false,
        public bool $isForwarded = false,
    ) {}

    /**
     * Возвращает отображаемое имя участника.
     */
    public function getDisplayName(): string
    {
        if ($this->username !== null) {
            return '@' . $this->username;
        }

        return $this->name ?? $this->id;
    }
}
