<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The HTTP door into the process registry for producers that do not live in
 * this PHP process: same-origin iframe UI-skills ('external') and the native
 * VM bridge ('native'). PHP packages call ProcessRegistry directly instead.
 *
 * action: begin | progress | heartbeat | complete | fail
 * progress: -1 (or omitted) means "no value" — JSON has no optional ints in
 * this setter-hydrated payload, and null must stay expressible (indeterminate).
 */
#[AsProtectedPayload(
    path: '/os/process/report',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class ProcessReportPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    private string $action = '';
    private string $id = '';
    private string $source = '';
    private string $origin = 'external';
    private string $title = '';
    private int $progress = -1;
    private string $detail = '';

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        $errors = [];
        if (!in_array($this->action, ['begin', 'progress', 'heartbeat', 'complete', 'fail'], true)) {
            $errors['action'][] = 'action must be one of: begin, progress, heartbeat, complete, fail';
        }
        if (trim($this->id) === '') {
            $errors['id'][] = 'id is required';
        }
        if ($this->action === 'begin' && trim($this->title) === '') {
            $errors['title'][] = 'title is required for begin';
        }

        return $errors;
    }

    public function getAction(): string { return $this->action; }
    public function setAction(string $action): void { $this->action = $action; }

    public function getId(): string { return $this->id; }
    public function setId(string $id): void { $this->id = $id; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): void { $this->source = $source; }

    public function getOrigin(): string { return $this->origin; }
    public function setOrigin(string $origin): void { $this->origin = $origin; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }

    public function getProgress(): int { return $this->progress; }
    public function setProgress(int $progress): void { $this->progress = $progress; }

    public function getDetail(): string { return $this->detail; }
    public function setDetail(string $detail): void { $this->detail = $detail; }

    /** The wire's -1 sentinel mapped back to "no value". */
    public function progressOrNull(): ?int
    {
        return $this->progress < 0 ? null : min(100, $this->progress);
    }

    public function detailOrNull(): ?string
    {
        return trim($this->detail) === '' ? null : $this->detail;
    }
}
