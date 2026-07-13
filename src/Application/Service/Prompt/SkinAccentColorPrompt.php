<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * The "pick one accent colour for a mood" system prompt used by the live-skin
 * generator. Migrated out of the inline string in
 * {@see \Semitexa\Os\Application\Console\Command\DesignSkinCommand::seedFromModel()}.
 *
 * No variables — a fixed instruction. The described mood/scene is the user
 * message, not part of this system prompt.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'os',
    description: 'Live-skin accent-colour picker: reply with one #rrggbb hex for a mood.',
)]
final class SkinAccentColorPrompt implements PromptDefinitionInterface
{
    public const ID = 'os.skin.accent-color';

    public function system(): string
    {
        return <<<'PROMPT'
        You choose ONE brand accent colour for a UI theme from a described mood or scene. Reply with ONLY a single hex colour in the form #rrggbb — no words, no explanation, no code fences.
        PROMPT;
    }
}
