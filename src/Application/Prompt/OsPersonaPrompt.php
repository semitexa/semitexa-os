<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * The Semitexa OS persona prompt. The body lives in
 * resources/prompts/os.persona.twig.
 *
 * A self-binding prompt: instead of the caller assembling a stringly-keyed
 * variables array, it carries typed data ({@see $assistantName}, {@see $userName})
 * exposed through getters, and the template reads them via dot access
 * (`{{ prompt.assistantName }}` / `{{ prompt.userName }}`). Build a bound
 * instance with {@see withData()} and pass it straight to the renderer. The
 * catalog still discovers this class parameterless (both args are optional), so
 * listing and DB overrides are unaffected.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    template: 'resources/prompts/os.persona.twig',
    description: 'Semitexa OS persona framing (intent-first assistant identity).',
)]
final class OsPersonaPrompt implements BoundPromptInterface
{
    public const ID = 'os.persona';

    public function __construct(
        private readonly ?string $assistantName = null,
        private readonly ?string $userName = null,
    ) {}

    /** An immutable copy bound to the given data — safe to render per request. */
    public function withData(string $assistantName, string $userName): self
    {
        return new self($assistantName, $userName);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function assistantName(): string
    {
        return (string) $this->assistantName;
    }

    public function userName(): string
    {
        return (string) $this->userName;
    }
}
