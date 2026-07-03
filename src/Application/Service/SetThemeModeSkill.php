<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Switch the interface light/dark mode by talking to the OS ("switch to light
 * mode", "перемкни на темну тему", "use the system theme"). The planner extracts
 * the target into the `mode` argument; this skill persists it via
 * {@see OsPreferences}, and the shell reflects it on the next preferences poll.
 *
 * `auto` follows the OS colour-scheme (with a time-of-day fallback, resolved
 * client-side). Low-risk and reversible, so it runs without a gate.
 */
#[AsAiSkill(
    name: 'set-theme-mode',
    summary: 'Switch the interface between dark, light, and auto (system) mode.',
    useWhen: 'The user wants to change the interface light/dark mode — e.g. "switch to light mode", "make it dark", "use the system theme", "перемкни на світлу тему", "зроби темний режим", "хай визначає автоматично". Put the target in the `mode` argument as one of: dark, light, auto.',
    avoidWhen: 'The user wants to restyle the colours/mood of the interface (that is design-skin / reset-skin), not switch light vs dark.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['mode'],
    channels: ['web'],
)]
final class SetThemeModeSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $mode = $this->normalise((string) ($arguments['mode'] ?? ''));
        if ($mode === null) {
            return 'Would you like the interface dark, light, or automatic?';
        }

        (new OsPreferences())->setThemeMode($mode);

        return match ($mode) {
            'dark' => 'Done — switched to dark mode.',
            'light' => 'Done — switched to light mode.',
            default => 'Done — the interface now follows your system (auto).',
        };
    }

    /** Map loose phrasing onto a canonical mode, or null if none recognised. */
    private function normalise(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, 'light') || str_contains($v, 'світл') || str_contains($v, 'світи') || str_contains($v, 'day') || str_contains($v, 'ден')) {
            return 'light';
        }
        if (str_contains($v, 'dark') || str_contains($v, 'темн') || str_contains($v, 'night') || str_contains($v, 'ніч')) {
            return 'dark';
        }
        if (str_contains($v, 'auto') || str_contains($v, 'system') || str_contains($v, 'систем') || str_contains($v, 'авто')) {
            return 'auto';
        }

        return null;
    }
}
