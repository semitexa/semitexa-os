<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Switch the OS language by talking to it ("перейди на українську",
 * "switch to English", "sprich Deutsch"). The planner extracts the target
 * into the `locale` argument; this skill persists it via {@see OsPreferences}
 * (validated against the tenant's effective pack) — the shell re-renders its
 * strings on the next preferences poll and the assistant replies in the new
 * language from the next turn.
 */
#[AsAiSkill(
    name: 'set-locale',
    summary: 'Switch the OS interface and assistant language.',
    useWhen: 'The user wants the OS or the assistant to speak another language — e.g. "перейди на українську", "switch to English", "spreche Deutsch", "mów po polsku". Put the target in the `locale` argument as a two-letter code (uk, en, de, pl, ru) or the language name.',
    avoidWhen: 'The user asks to TRANSLATE a specific text (answer directly), or to change voice/tone rather than language.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['locale'],
    argumentHints: [
        'locale' => 'Two-letter locale code (uk|en|de|pl|ru) or a language name like "українська"/"English".',
    ],
    channels: ['web'],
)]
final class SetLocaleSkill implements InvocableSkillInterface
{
    /** Tenant-aware via container injection (SkillLoopRunner's DI resolver). */
    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    public function invoke(array $arguments): string
    {
        $locale = $this->normalise((string) ($arguments['locale'] ?? ''));
        if ($locale === null) {
            return OsReplies::say(
                'locale.ask',
                [],
                'Which language would you like — українська, English, Deutsch, polski?',
            );
        }

        try {
            $this->prefs()->setLocale($locale);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return match ($locale) {
            'uk' => 'Готово — переходжу на українську.',
            'en' => 'Done — switching to English.',
            'de' => 'Erledigt — ich spreche jetzt Deutsch.',
            'pl' => 'Gotowe — przechodzę na polski.',
            'ru' => 'Готово — переключаюсь.',
            default => 'Done — language switched to ' . $locale . '.',
        };
    }

    /** Map loose phrasing (codes, language names, native phrases) to a code. */
    private function normalise(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['uk', 'en', 'de', 'pl', 'ru'], true)) {
            return $v;
        }
        if (str_contains($v, 'укра') || str_contains($v, 'ukrain')) {
            return 'uk';
        }
        if (str_contains($v, 'англ') || str_contains($v, 'english') || str_contains($v, 'engl')) {
            return 'en';
        }
        if (str_contains($v, 'нім') || str_contains($v, 'deutsch') || str_contains($v, 'german')) {
            return 'de';
        }
        if (str_contains($v, 'поль') || str_contains($v, 'polsk') || str_contains($v, 'polish')) {
            return 'pl';
        }
        if (str_contains($v, 'рос') || str_contains($v, 'russ')) {
            return 'ru';
        }

        return null;
    }

    private function prefs(): OsPreferences
    {
        return isset($this->prefs) ? $this->prefs : new OsPreferences();
    }
}
