<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;

/**
 * The sign-in page.
 *
 * Public by necessity — it is the one door someone with no session may knock on.
 * Its path is env-configurable alongside the shell's: an install that moves the
 * shell to /admin moves the form with it.
 */
#[AsPublicPayload(
    path: 'env::SEMITEXA_OS_LOGIN_PATH::/os/login',
    methods: ['GET'],
    responseWith: OsLoginResource::class,
)]
final class OsLoginPayload
{
    /** Where the visitor was heading when the form interrupted them. */
    private string $next = '';

    public function getNext(): string
    {
        return $this->next;
    }

    public function setNext(string $next): void
    {
        $this->next = $next;
    }
}
