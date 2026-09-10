<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Os\Application\Payload\Request\PasswordChangePayload;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Platform\User\Application\Service\PasswordHasher;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;

/**
 * Sets the signed-in person's own password.
 *
 * Changing a password used to mean asking an operator to run a CLI command,
 * which makes the operator a keylogger by protocol: the only way to get a new
 * password was for someone else to choose it and tell you.
 *
 * `user:password` stays as the operator's path — it is also the way out of a
 * lockout, and someone locked out cannot reach this route by definition.
 *
 * The current password is verified here even though the caller is already
 * signed in. A session is a bearer token; without this check a borrowed one
 * could lock the owner out of their own account.
 */
#[AsPayloadHandler(payload: PasswordChangePayload::class, resource: ResourceResponse::class)]
final class PasswordChangeHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected OsAdminSession $admins;

    #[InjectAsReadonly]
    protected PlatformUserRepositoryInterface $users;

    #[InjectAsReadonly]
    protected PasswordHasher $hasher;

    public function handle(PasswordChangePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $user = $this->admins->current(isset($this->session) ? $this->session : null);

        if ($user === null) {
            return $this->json($resource, 401, ['error' => 'Sign in to the console first.']);
        }

        if (!$user->verifyPassword($payload->getCurrentPassword())) {
            // Deliberately the same shape as any other refusal, and deliberately
            // not "that is not your password" — this route is reachable only by
            // someone already holding the session, so the useful signal is that
            // it did not work, not which half was wrong.
            return $this->json($resource, 422, ['errors' => ['currentPassword' => ['That is not your current password.']]]);
        }

        $new = $payload->getNewPassword();

        if ($user->verifyPassword($new)) {
            return $this->json($resource, 422, [
                'errors' => ['newPassword' => ['That is the password you already have.']],
            ]);
        }

        try {
            $hash = $this->hasher->hash($new);
        } catch (\InvalidArgumentException $e) {
            // The hasher owns the policy — length, emptiness — so its message is
            // the one the person reads. Duplicating the rule here would let the
            // two drift.
            return $this->json($resource, 422, ['errors' => ['newPassword' => [$e->getMessage()]]]);
        }

        $this->users->update($user->withPasswordHash($hash));

        // The session id is rotated: a password change is exactly the moment
        // someone acts on a suspicion that an old session is not theirs alone.
        $this->session->regenerate();

        return $this->json($resource, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $body */
    private function json(ResourceResponse $resource, int $status, array $body): ResourceResponse
    {
        return $resource
            ->setStatusCode($status)
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
