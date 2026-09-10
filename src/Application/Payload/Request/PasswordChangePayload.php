<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;

/**
 * Change your own password.
 *
 * On the console rather than a route of its own: the console already has one
 * place a person changes their own things, it is already gated for a signed-in
 * operator, and a second door for one field is a second door to get wrong. An
 * auth-touching surface that inherits an existing gate is worth more than one
 * that is easier to link to.
 *
 * The CURRENT password is required and is not a formality. A session is a
 * bearer token: without this, anyone holding a borrowed one could lock the
 * owner out of their own account, and the owner's own password would be the
 * thing that stopped working.
 */
#[AsProtectedPayload(
    path: '/os/password',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/json'],
    produces: ['application/json'],
)]
final class PasswordChangePayload implements ValidatablePayloadInterface, OsContentSurfaceInterface
{
    private string $currentPassword = '';
    private string $newPassword = '';
    private string $repeatPassword = '';

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        $errors = [];

        if ($this->currentPassword === '') {
            $errors['currentPassword'] = ['Enter your current password.'];
        }
        if ($this->newPassword === '') {
            $errors['newPassword'] = ['Enter a new password.'];
        }
        // Checked here rather than after hashing: a typo in the repeat should
        // not cost a password hash, and the message is about the form, not the
        // policy.
        if ($this->newPassword !== '' && $this->newPassword !== $this->repeatPassword) {
            $errors['repeatPassword'] = ['The two new passwords do not match.'];
        }

        return $errors;
    }

    public function getCurrentPassword(): string
    {
        return $this->currentPassword;
    }

    public function setCurrentPassword(#[\SensitiveParameter] string $currentPassword): void
    {
        $this->currentPassword = $currentPassword;
    }

    public function getNewPassword(): string
    {
        return $this->newPassword;
    }

    public function setNewPassword(#[\SensitiveParameter] string $newPassword): void
    {
        $this->newPassword = $newPassword;
    }

    public function setRepeatPassword(#[\SensitiveParameter] string $repeatPassword): void
    {
        $this->repeatPassword = $repeatPassword;
    }
}
