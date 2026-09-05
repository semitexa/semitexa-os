<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Model;

use Semitexa\Os\Domain\Enum\ProcessStatus;

/**
 * Something the OS is doing, or has just finished doing — what the Chill panel
 * shows and what a background job reports into.
 *
 * The id here is the one the producer knows. Storage prefixes it to keep two
 * sources from colliding; that prefixing is the registry's concern and never
 * reaches this far.
 */
final readonly class Process
{
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $source,
        private string $origin,
        private string $title,
        private string $status,
        private ?\DateTimeImmutable $startedAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
        private ?int $progress = null,
        private ?string $detail = null,
        private ?\DateTimeImmutable $completedAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /** The status as the OS understands it; anything unrecognised is still running. */
    public function statusEnum(): ProcessStatus
    {
        return ProcessStatus::tryFrom($this->status) ?? ProcessStatus::Running;
    }

    public function isRunning(): bool
    {
        return $this->statusEnum() === ProcessStatus::Running;
    }

    /**
     * A copy with some fields replaced.
     *
     * `array_key_exists` for the nullable three: a `??` fallback would refuse to
     * clear progress or detail, and a process that can never go indeterminate
     * again is a lie the panel would keep showing.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $take = fn (string $key, mixed $current): mixed => array_key_exists($key, $changes) ? $changes[$key] : $current;

        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            source: $take('source', $this->source),
            origin: $take('origin', $this->origin),
            title: $take('title', $this->title),
            status: $take('status', $this->status),
            startedAt: $take('startedAt', $this->startedAt),
            updatedAt: $take('updatedAt', $this->updatedAt),
            progress: $take('progress', $this->progress),
            detail: $take('detail', $this->detail),
            completedAt: $take('completedAt', $this->completedAt),
        );
    }
}
