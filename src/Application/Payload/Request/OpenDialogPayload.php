<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * Open a UI-skill as a dialog window in Focus. The skill is re-validated as a
 * UI-skill against the live manifest by the handler.
 */
#[AsProtectedPayload(
    path: '/os/dialog/open',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class OpenDialogPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    private string $skill = '';
    private ?string $parentId = null;
    private string $path = '';

    private string $ref = '';

    /**
     * @return array<string, list<string>>
     */
    public function validate(): array
    {
        $errors = [];
        if (trim($this->skill) === '') {
            $errors['skill'] = ['A UI-skill name is required.'];
        }

        return $errors;
    }

    public function getSkill(): string
    {
        return $this->skill;
    }

    public function setSkill(string $skill): void
    {
        $this->skill = $skill;
    }

    public function getParentId(): ?string
    {
        return $this->parentId;
    }

    public function setParentId(?string $parentId): void
    {
        $this->parentId = $parentId !== '' ? $parentId : null;
    }

    /**
     * Optional record the dialog should open at, appended as `?ref=` — the map's
     * name for it, e.g. 'regmus:page:3'.
     *
     * Separate from `path` on purpose: a filesystem location and a record
     * identity are different things, and overloading one for the other is how
     * a Files dialog ends up trying to list a database row.
     */
    public function getRef(): string
    {
        return $this->ref;
    }

    public function setRef(string $ref): void
    {
        $this->ref = trim($ref);
    }

    /** Optional context appended to the skill's entry as `?path=` (e.g. open Files at a folder). */
    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }
}
