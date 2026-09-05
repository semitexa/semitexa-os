<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Model;

/**
 * What the assistant remembers of a conversation older than the verbatim
 * window, and how far that memory reaches.
 *
 * `coveredThroughId` is the load-bearing field: it is the newest transcript
 * turn already folded in, and the boundary the live window is fetched after.
 * A summary and a window that disagree about it either replay a turn twice or
 * lose it entirely, which is why this is a decision the OS makes rather than a
 * column MySQL happens to hold.
 */
final readonly class ConversationSummary
{
    public function __construct(
        private string $tenantId,
        private string $summaryText = '',
        private string $activeIntent = '',
        private string $coveredThroughId = '',
        private ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getSummaryText(): string
    {
        return $this->summaryText;
    }

    /** One sentence naming the user's current cross-turn focus, or ''. */
    public function getActiveIntent(): string
    {
        return $this->activeIntent;
    }

    /** Id of the newest transcript turn already folded in. */
    public function getCoveredThroughId(): string
    {
        return $this->coveredThroughId;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Nothing has been folded in yet.
     *
     * Deliberately keyed on the cursor rather than the text: a fold that
     * summarised to an empty string still moved the boundary, and treating that
     * as "never summarised" would replay those turns forever.
     */
    public function isEmpty(): bool
    {
        return $this->coveredThroughId === '';
    }
}
