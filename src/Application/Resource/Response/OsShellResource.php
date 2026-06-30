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
}
