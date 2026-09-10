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
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Os\Application\Service\OsSkillText;
use Semitexa\Os\Application\Service\InputLayoutStore;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Os\Application\Service\ProviderHealthCache;
use Semitexa\Os\Application\Service\SkillLoopRunner;
use Semitexa\Os\Domain\Enum\WindowMode;

/**
 * Renders the OS shell with boot context: the discovered skills and the LLM
 * provider's identity/health.
 */
#[AsPayloadHandler(payload: OsShellPayload::class, resource: OsShellResource::class)]
final class OsShellHandler implements TypedHandlerInterface
{
    /**
     * The route the launcher's "Your apps" section is fed by. Lives in
     * semitexa/webapps, which is neither a dependency of this package nor part
     * of ultimate — so a normal install does not have it.
     */
    private const WEB_APPS_PAYLOAD = 'Semitexa\\WebApps\\Application\\Payload\\Request\\WebAppsListPayload';

    #[InjectAsReadonly]
    protected SkillLoopRunner $runner;

    #[InjectAsReadonly]
    protected ProviderHealthCache $providerHealth;

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
        //
        // It is also scoped to the surfaces the OS can execute on. It was not,
        // and the sentence above was false for console-only skills: the shell
        // offered them and SkillLoopRunner, which narrows to the same channels
        // before planning, refused to run them.
        $scope = $this->skillScope->forSession($session);
        $manifest = $scope === null
            ? SkillManifest::emptyFor(SkillLoopRunner::OS_CHANNELS)
            : $this->skills->manifestFor($scope, SkillLoopRunner::OS_CHANNELS);

        $skills = [];
        foreach ($manifest->skills() as $skill) {
            $skills[] = [
                'name' => $skill->name,
                'summary' => OsSkillText::summary($skill),
                'risk' => $skill->riskLevel->value,
                'icon' => $skill->icon,
                'entry' => $skill->entry,
                'is_ui' => $skill->isUi(),
            ];
        }

        $provider = $this->runner->provider();

        // Through the cache, not straight at the provider. MEASURED: the direct
        // call cost 280.3 / 231.7 / 220.4 ms against the live endpoint while this
        // request spent 2.76 ms in the database and 6.71 ms rendering — so
        // opening the OS waited on Google for nearly the whole request, to draw
        // a status dot. The answer is shared per worker for a short window.
        $healthy = $this->providerHealth->isHealthy($provider);

        [$locale, $strings] = $this->localeBundle();

        return $resource
            ->withSkills($skills)
            ->withFeatures($this->features($session))
            ->withProvider($provider->name(), $provider->model(), $healthy, self::isLocal($provider->baseUrl()))
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
    /**
     * Optional packages this install actually has.
     *
     * Asked by class rather than by route because it is the honest question —
     * the payload class existing IS the package being installed — and because
     * the name is a constant here, never something a caller supplied.
     *
     * Carries the role too, so the shell can keep an operator surface out of a
     * content editor's way rather than showing everyone everything.
     *
     * @return array<string, bool>
     */
    /**
     * Whether the model answers from this machine.
     *
     * Decided from the base URL rather than from a list of provider names: a
     * remote Ollama is not local however it is spelled, and a list would need
     * editing every time a provider is added — which is how the claim would go
     * stale again.
     */
    private static function isLocal(string $baseUrl): bool
    {
        // parse_url keeps the brackets on an IPv6 host: 'http://[::1]:11434'
        // yields '[::1]', so comparing against '::1' never matched and a
        // loopback install was reported as remote.
        $host = trim(strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?: '')), '[]');

        return $host === 'localhost'
            || $host === '::1'
            || str_starts_with($host, '127.')
            || $host === 'host.docker.internal';
    }

    private function features(?SessionInterface $session): array
    {
        $role = $this->admins->currentPrincipal($session)?->user->getRole();

        return [
            'webApps' => class_exists(self::WEB_APPS_PAYLOAD),
            // X-Ray is observability: loop internals, provider health, a live
            // trace. Useful to whoever runs the install and noise to whoever
            // uses it — a museum employee reading "Planner over the
            // SkillManifest" learns nothing except that this is not for them.
            'operator' => $role === UserRole::Owner || $role === UserRole::Admin,
        ];
    }

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
