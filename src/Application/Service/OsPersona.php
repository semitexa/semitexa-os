<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiPersona;
use Semitexa\Llm\Domain\Contract\AiPersonaInterface;
use Semitexa\Os\Application\Prompt\OsPersonaPrompt;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

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
    private ?PromptTemplate $template = null;

    public function contextName(): string
    {
        return 'os';
    }

    public function systemFraming(): string
    {
        // Raw data only — the template's {% if user_name %} builds the greeting
        // (or the ask-for-name guidance when the name is not known yet).
        $prefs = (new OsPreferences())->all();

        return $this->renderer()->renderTemplate(
            $this->template ??= (new PromptRegistry())
                ->buildFromClasses([OsPersonaPrompt::class])[OsPersonaPrompt::ID],
            [
                'assistant_name' => $prefs['assistant_name'],
                'user_name' => $prefs['user_name'],
            ],
        )->system;
    }

    private function renderer(): PromptRenderer
    {
        return $this->renderer ??= new PromptRenderer();
    }
}
