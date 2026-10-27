<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Llm\Domain\Model\ScopedSkillManifest;
use Semitexa\Llm\Domain\Model\SkillEntry;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Os\Pipeline\OsAdminGate;
use Semitexa\Platform\User\Auth\UserPrincipal;

/**
 * Whether the person of the running request opens a console surface, asked
 * from code that is about to send them to one: a skill that opens a window.
 * The open-web-app skill added an editor's site to the site's list and opened
 * a window the gate then refused.
 *
 * The role rule is the gate's ({@see OsAdminGate::roleOpens()}). Sign-in off
 * opens everything, as the gate does; a request with no console user opens
 * nothing.
 */
#[AsService]
final class OsConsoleAccess
{
    #[InjectAsReadonly]
    protected OsAuthPolicy $policy;

    /** The routes, to tell which payload a window's entry opens. */
    #[InjectAsReadonly]
    protected AttributeDiscovery $routes;

    /** @param class-string $surface */
    public function opens(string $surface): bool
    {
        if (!isset($this->policy) || !$this->policy->isRequired()) {
            return true;
        }

        $principal = AuthContextStore::getUser();

        return $principal instanceof UserPrincipal && OsAdminGate::roleOpens($principal->user->getRole(), $surface);
    }

    /**
     * Whether the window at $entry opens for this person: the gate's rule on
     * the payload its route serves. An entry that is not a console surface
     * (or no route) is not the gate's to refuse.
     */
    public function opensEntry(string $entry): bool
    {
        $path = parse_url($entry, PHP_URL_PATH);
        $route = isset($this->routes) && is_string($path) && $path !== '' ? $this->routes->findRoute($path, 'GET') : null;
        $class = is_array($route) && is_string($route['class'] ?? null) ? $route['class'] : '';

        return $class === '' || !is_a($class, OsSurfacePayloadInterface::class, true) || $this->opens($class);
    }

    /**
     * $manifest without the windows this person would be refused: an editor
     * was offered Prompts, by the shell, the planner and the dialog opener,
     * and the window then answered 403.
     */
    public function narrow(ScopedSkillManifest $manifest): ScopedSkillManifest
    {
        if (!isset($this->policy) || !$this->policy->isRequired()) {
            return $manifest;
        }

        return $manifest->without(fn (SkillEntry $skill): bool => $skill->isUi() && is_string($skill->entry) && $skill->entry !== '' && !$this->opensEntry($skill->entry));
    }
}
