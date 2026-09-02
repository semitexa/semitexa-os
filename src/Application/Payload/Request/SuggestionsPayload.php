<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Read the adaptive Ambient suggestions. The shell passes the client's local
 * wall-clock context (`hour`, `weekend`) so chips match the user's actual time
 * regardless of server timezone. All fields are optional — the engine falls
 * back to server time and sensible defaults.
 *
 * Query string is kept as raw strings and coerced in the getters, so query
 * hydration never trips strict scalar typing.
 */
#[AsProtectedPayload(
    path: '/os/suggestions',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['application/json'],
)]
final class SuggestionsPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    private string $hour = '';

    private string $weekend = '';

    private string $limit = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        return [];
    }

    /** Client-local hour 0–23, or -1 when not supplied (engine uses server time). */
    public function getHour(): int
    {
        return is_numeric($this->hour) ? (int) $this->hour : -1;
    }

    public function isWeekend(): bool
    {
        return $this->weekend === '1' || strtolower($this->weekend) === 'true';
    }

    public function getLimit(): int
    {
        return is_numeric($this->limit) ? (int) $this->limit : 4;
    }

    public function setHour(string $hour): void
    {
        $this->hour = $hour;
    }

    public function setWeekend(string $weekend): void
    {
        $this->weekend = $weekend;
    }

    public function setLimit(string $limit): void
    {
        $this->limit = $limit;
    }
}
