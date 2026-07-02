<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Read the saved dialog transcript. Optional `limit` returns only the most
 * recent N turns (0/absent = the whole history).
 */
#[AsPublicPayload(
    path: '/os/conversation',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class ConversationPayload implements ValidatablePayloadInterface
{
    private string $limit = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }

    public function getLimit(): int
    {
        return is_numeric($this->limit) ? max(0, (int) $this->limit) : 0;
    }

    public function setLimit(string $limit): void
    {
        $this->limit = $limit;
    }
}
