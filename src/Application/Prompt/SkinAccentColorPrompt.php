<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * Thin prompt definition — the body lives in resources/prompts/os.skin.accent-color.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    template: 'os.skin.accent-color.twig',
    description: 'Live-skin accent-colour picker: reply with one #rrggbb hex for a mood.',
)]
final class SkinAccentColorPrompt
{
    public const ID = 'os.skin.accent-color';
}
