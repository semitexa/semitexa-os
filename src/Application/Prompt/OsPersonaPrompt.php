<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * Thin prompt definition — the body lives in resources/prompts/os.persona.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    description: 'Semitexa OS persona framing (intent-first assistant identity).',
)]
final class OsPersonaPrompt
{
    public const ID = 'os.persona';
}
