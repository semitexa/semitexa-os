<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Semitexa\Os\Application\Payload\Request\OsShellPayload;
use Semitexa\Os\Application\Resource\Response\OsShellResource;
use Semitexa\Os\Application\Service\InputLayoutStore;
use Semitexa\Os\Application\Service\OsPreferences;
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
        $manifest = (new SkillRegistry())->buildManifest();

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
     * Locale: the explicit OS preference wins; '' = follow the request's own
     * resolution (the locale phase already set the context). Strings: every
     * `os.shell.*` catalog key resolved through TranslationService — so
     * per-tenant translation overrides apply — keyed WITHOUT the prefix (the
     * shell's t() keys). English key set is the canonical enumeration.
     *
     * @return array{string, array<string, string>}
     */
    private function localeBundle(): array
    {
        $locale = $this->prefs->locale();
        if ($locale === '') {
            $locale = \Semitexa\Locale\Context\LocaleContextStore::getLocale();
        }

        $strings = [];
        try {
            $service = \Semitexa\Ssr\Application\Service\I18n\Translator::getService();
            $catalog = \Semitexa\Ssr\Application\Service\I18n\Translator::getCatalog();
            foreach ($catalog->keys('en', 'os.shell.') as $key) {
                $strings[substr($key, \strlen('os.shell.'))] = $service->trans($key, [], $locale);
            }
        } catch (\Throwable) {
            // Best-effort: the shell's inline English fallbacks render the UI.
        }

        return [$locale, $strings];
    }
}
