<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Llm\Application\Service\TenantSkillScope;
use Semitexa\Os\Application\Payload\Request\OsShellPayload;
use Semitexa\Os\Application\Resource\Response\OsShellResource;
use Semitexa\Os\Application\Service\InputLayoutStore;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Os\Application\Service\SkillLoopRunner;
use Semitexa\Os\Domain\Enum\WindowMode;

/**
 * Renders the OS shell with boot context: the discovered skills and the LLM
 * provider's identity/health.
 */
#[AsPayloadHandler(payload: OsShellPayload::class, resource: OsShellResource::class)]
final class OsShellHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected SkillLoopRunner $runner;

    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    #[InjectAsReadonly]
    protected InputLayoutStore $inputLayouts;

    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected OsAdminSession $admins;

    #[InjectAsReadonly]
    protected OsAuthPolicy $authPolicy;

    #[InjectAsReadonly]
    protected OsSkillScope $skillScope;

    #[InjectAsReadonly]
    protected TenantSkillScope $skills;

    /**
     * The window-hosting mode this install is *permitted* to use. The shell
     * still probes at runtime before it promotes anything (see the shell's
     * windowMode resolution), so `os` degrades to iframes for a browser client.
     */
    #[Config(env: 'SEMITEXA_WINDOW_MODE', default: WindowMode::Web)]
    protected WindowMode $windowMode;

    /** Where the local native-window bridge listens (OS mode only). */
    #[Config(env: 'SEMITEXA_BRIDGE_URL', default: 'http://127.0.0.1:8777')]
    protected string $bridgeUrl;

    public function handle(OsShellPayload $payload, OsShellResource $resource): OsShellResource
    {
        // The shell is the one OS surface a person navigates to rather than
        // fetches, so an anonymous visitor gets the sign-in form instead of the
        // bare 401 the rest of /os answers with. Nothing below this line runs
        // for them — not the skill manifest, not the provider health probe —
        // because every one of those is a fact about the site they have not
        // earned yet.
        $session = isset($this->session) ? $this->session : null;

        if ($this->authPolicy->isRequired() && !$this->admins->isSignedIn($session)) {
            if ($session !== null) {
                $this->admins->rememberIntendedPath($session, $this->authPolicy->shellPath());
            }

            return $resource->setRedirect($this->authPolicy->loginPath());
        }

        // What the shell lists is what this admin may run: the manifest is
        // scoped to their tenant, so a museum's admin never sees — and the
        // planner never proposes — the clinic's skills.
        $scope = $this->skillScope->forSession($session);
        $manifest = $scope === null
            ? new \Semitexa\Llm\Domain\Model\SkillManifest('semitexa.ai-skills/v1', gmdate('c'), [])
            : $this->skills->manifestFor($scope);

        $skills = [];
        foreach ($manifest->skills as $skill) {
            $skills[] = [
                'name' => $skill->name,
                'summary' => $skill->summary,
                'risk' => $skill->riskLevel->value,
                'icon' => $skill->icon,
                'entry' => $skill->entry,
                'is_ui' => $skill->isUi(),
            ];
        }

        $provider = $this->runner->provider();

        $healthy = false;
        try {
            $healthy = $provider->healthCheck();
        } catch (\Throwable) {
            $healthy = false;
        }

        [$locale, $strings] = $this->localeBundle();

        return $resource
            ->withSkills($skills)
            ->withProvider($provider->name(), $provider->model(), $healthy)
            ->withAssistantName($this->prefs->assistantName())
            ->withUserName($this->prefs->userName())
            ->withLocale($locale, $strings)
            ->withInputLayouts($this->inputLayouts->state())
            ->withWindowMode($this->windowMode->value, $this->bridgeUrl);
    }

    /**
     * The OS locale + its resolved shell string bundle for the boot payload.
     *
     * Locale: whatever {@see OsPreferences::language()} decides — the same
     * answer the assistant's default name is spelled in. Strings: every
     * `os.shell.*` catalog key resolved through TranslationService — so
     * per-tenant translation overrides apply — keyed WITHOUT the prefix (the
     * shell's t() keys). English key set is the canonical enumeration.
     *
     * @return array{string, array<string, string>}
     */
    private function localeBundle(): array
    {
        $locale = $this->prefs->language();

        $strings = [];
        try {
            $service = \Semitexa\Ssr\Application\Service\I18n\Translator::getService();
            $catalog = \Semitexa\Ssr\Application\Service\I18n\Translator::getCatalog();
            foreach ($catalog->keys('en', 'os.shell.') as $key) {
                $short = substr($key, \strlen('os.shell.'));
                $translated = $service->trans($key, [], $locale);

                // trans() hands back the key itself when the active locale has
                // no catalog for it — the OS ships en and uk, and a site whose
                // LOCALE_DEFAULT is neither would otherwise render literal
                // 'os.shell.hi' across the whole console. English is a poor
                // answer for such a visitor; a raw key is not an answer at all.
                if ($translated === $key && $locale !== 'en') {
                    $translated = $service->trans($key, [], 'en');
                }

                $strings[$short] = $translated;
            }
        } catch (\Throwable) {
            // Best-effort: the shell's inline English fallbacks render the UI.
        }

        return [$locale, $strings];
    }
}
