<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

/**
 * The sign-in page.
 *
 * Deliberately austere: no skills, no provider, no boot payload. Everything the
 * shell knows is something an anonymous visitor should not be told, so the form
 * is rendered by a resource that cannot accidentally carry it.
 */
#[AsResource(handle: 'os-login', template: '@project-layouts-Os/login.html.twig')]
final class OsLoginResource extends HtmlResponse implements ResourceInterface
{
    public function withForm(string $action, string $logoutAction, string $csrfToken, string $next): static
    {
        return $this
            ->with('action', $action)
            ->with('logoutAction', $logoutAction)
            ->with('csrfToken', $csrfToken)
            ->with('next', $next);
    }

    /** The address to prefill, so a failed attempt does not retype it. */
    public function withEmail(string $email): static
    {
        return $this->with('email', $email);
    }

    /**
     * One neutral sentence. Never say which half was wrong: the difference is
     * exactly what turns a login form into an account-enumeration oracle.
     */
    public function withError(?string $error): static
    {
        return $this->with('error', $error);
    }

    public function withNotice(?string $notice): static
    {
        return $this->with('notice', $notice);
    }

    /**
     * The name the sign-in page introduces itself with.
     *
     * Left unset by the OS's own login handlers, so the template falls back to
     * the product wordmark. It used to be fed the chat assistant's nickname,
     * which put an unexplained first name in front of an anonymous visitor and
     * meant renaming your assistant rebranded the sign-in page: the two
     * identities are unrelated. A host that HAS a site or tenant name of its
     * own is what this is for.
     */
    public function withBranding(string $title): static
    {
        return $this->with('brandTitle', $title);
    }
}
