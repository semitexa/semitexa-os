<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;
use Semitexa\Ssr\Application\Service\Seo\Sitemap\NotInSitemap;

/**
 * The submitted credentials.
 *
 * Public for the same reason as the form itself. The password is carried in a
 * form POST rather than JSON so the browser's own password manager recognises
 * the flow and can offer to store it.
 */
#[NotInSitemap]
#[AsPublicPayload(
    path: 'env::SEMITEXA_OS_LOGIN_PATH::/os/login',
    methods: ['POST'],
    responseWith: OsLoginResource::class,
    consumes: ['application/x-www-form-urlencoded'],
)]
final class OsLoginSubmitPayload
{
    private string $email = '';

    private string $password = '';

    private string $csrfToken = '';

    private string $next = '';

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(#[\SensitiveParameter] string $password): void
    {
        $this->password = $password;
    }

    public function getCsrfToken(): string
    {
        return $this->csrfToken;
    }

    public function setCsrfToken(string $csrfToken): void
    {
        $this->csrfToken = $csrfToken;
    }

    public function getNext(): string
    {
        return $this->next;
    }

    public function setNext(string $next): void
    {
        $this->next = $next;
    }
}
