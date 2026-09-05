<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Model;

/**
 * One thing said in the conversation — the user's intent or the assistant's
 * reply — as the append-only transcript keeps it.
 *
 * `meta` is an array here and a JSON string in the column; that encoding is the
 * table's business. What matters at this level is that a turn has a speaker,
 * words, and whatever the loop recorded alongside them.
 */
final readonly class ConversationTurn
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $role,
        private string $text,
        private array $meta = [],
        private ?\DateTimeImmutable $createdAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /** @return array<string, mixed> */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isFromUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }
}
