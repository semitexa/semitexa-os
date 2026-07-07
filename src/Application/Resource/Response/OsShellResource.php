<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Resource\Response;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Ssr\Application\Service\Http\Response\HtmlResponse;

/**
 * Renders the OS shell page. Carries a small amount of boot context — the
 * available skills and the active LLM provider — so the shell can show what it
 * is capable of before the first intent is ever typed (the "State Snapshot"
 * idea, in its thinnest v0 form).
 */
#[AsResource(handle: 'os-shell', template: '@project-layouts-Os/shell.html.twig')]
final class OsShellResource extends HtmlResponse implements ResourceInterface
{
    /**
     * @param list<array{name: string, summary: string, risk: string}> $skills
     */
    public function withSkills(array $skills): static
    {
        return $this->with('skills', $skills);
    }

    public function withProvider(string $name, string $model, bool $healthy): static
    {
        return $this
            ->with('providerName', $name)
            ->with('providerModel', $model)
            ->with('providerHealthy', $healthy);
    }

    /** The name the user calls their assistant (default "Semi"). */
    public function withAssistantName(string $name): static
    {
        return $this->with('assistantName', $name);
    }

    /** The user's own name, or '' when unknown (triggers onboarding). */
    public function withUserName(string $name): static
    {
        return $this->with('userName', $name);
    }

    /**
     * The OS interface locale and the resolved UI string bundle for it
     * (key => translated text, keys without the os.shell. prefix) — the shell's
     * t() helper reads these from the boot payload, with inline English
     * literals as the fallback.
     *
     * @param array<string, string> $strings
     */
    public function withLocale(string $locale, array $strings): static
    {
        return $this
            ->with('locale', $locale)
            ->with('strings', $strings);
    }

    /**
     * The install's declared window-hosting mode ('web' | 'os') and the local
     * bridge URL used to raise real native windows in OS mode. The shell treats
     * these as a *capability gate*; it still probes at runtime before promoting.
     */
    public function withWindowMode(string $mode, string $bridgeUrl): static
    {
        return $this
            ->with('windowMode', $mode)
            ->with('bridgeUrl', $bridgeUrl);
    }
}
