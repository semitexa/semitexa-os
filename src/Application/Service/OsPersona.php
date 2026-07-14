<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiPersona;
use Semitexa\Llm\Domain\Contract\AiPersonaInterface;
use Semitexa\Os\Application\Prompt\OsPersonaPrompt;
use Semitexa\Prompt\Application\Service\PromptRenderer;

/**
 * The Semitexa OS persona: the model IS the assistant at the heart of the
 * intent-first OS, personalised with the assistant's name and the user's name
 * from {@see OsPreferences}. Selected for the `os` situation by the
 * {@see \Semitexa\Llm\Application\Service\PersonaRegistry}.
 */
#[AsAiPersona(context: 'os')]
final class OsPersona implements AiPersonaInterface
{
    private ?PromptRenderer $renderer = null;

    public function contextName(): string
    {
        return 'os';
    }

    public function systemFraming(): string
    {
        // A self-binding prompt: typed data in, no stringly-keyed variables array.
        // The template's {% if prompt.userName %} builds the greeting (or the
        // ask-for-name guidance when the name is not known yet).
        $prefs = (new OsPreferences())->all();

        return $this->renderer()->render(
            (new OsPersonaPrompt())->withData($prefs['assistant_name'], $prefs['user_name']),
        )->system;
    }

    private function renderer(): PromptRenderer
    {
        return $this->renderer ??= new PromptRenderer();
    }
}
