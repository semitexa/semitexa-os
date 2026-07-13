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
        $prefs = (new OsPreferences())->all();
        $assistant = $prefs['assistant_name'];
        $userLine = $prefs['user_name'] !== ''
            ? ' You are speaking with ' . $prefs['user_name'] . '.'
            : ' You do not know the user\'s name yet. Early in the conversation, warmly ask what you should call them; and whenever they tell you their name in ANY phrasing ("I\'m Taras", "мене звати Тарас", "call me Sam", or just "Taras"), record it with the set-user-name skill (put the extracted name in its `name` argument). Ask once, naturally — never nag or repeat the same question.';

        return $this->renderer()->renderTemplate(
            $this->template ??= (new PromptRegistry())
                ->buildFromClasses([OsPersonaPrompt::class])[OsPersonaPrompt::ID],
            [
                'assistant_name' => $assistant,
                'user_line' => $userLine,
            ],
        )->system;
    }

    private function renderer(): PromptRenderer
    {
        return $this->renderer ??= new PromptRenderer();
    }
}
