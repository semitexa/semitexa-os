<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * Thin, self-binding prompt — body in resources/prompts/os.skin.accent-color.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    template: 'resources/prompts/os.skin.accent-color.twig',
    description: 'Live-skin accent-colour picker: reply with one #rrggbb hex for a mood.',
)]
final class SkinAccentColorPrompt implements BoundPromptInterface
{
    public const ID = 'os.skin.accent-color';

    public function promptId(): string
    {
        return self::ID;
    }
}
