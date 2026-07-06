<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Chat-channel companion of the Updates UI-skill: a read-only text digest of
 * the update state — release set, last run, recent version changes and their
 * release notes. Answers "what's new?" inline without raising a dialog.
 */
#[AsAiSkill(
    name: 'os:updates',
    summary: 'Report the update state: installed release set, last update run, recent changes and release notes.',
    useWhen: 'The user asks in chat what is new, what version is installed, whether updates ran, or what changed recently.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['web'],
)]
final class OsUpdatesSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $report = (new UpdatesReport())->build(historyLimit: 5);

        if (($report['available'] ?? false) !== true) {
            return 'The update system (semitexa/update) is not installed here, so there is no update journal to report.';
        }

        $lines = ['Semitexa OS — updates'];
        $lines[] = '─────────────────────';
        $lines[] = 'Updater     : semitexa/update ' . ((string) ($report['updater_version'] ?? '') ?: '(dev workspace)');
        $lines[] = 'Release set : ' . ((string) ($report['release_set'] ?? '') ?: 'n/a')
            . ' (' . (int) $report['packages'] . ' packages)';

        $lastRun = $report['last_run'];
        if (is_array($lastRun)) {
            $lines[] = sprintf(
                'Last run    : %s — %s%s (%s)',
                substr((string) $lastRun['started_at'], 0, 19),
                (string) $lastRun['outcome'],
                $lastRun['failed_stage'] !== null ? ' @ ' . $lastRun['failed_stage'] : '',
                (string) $lastRun['kind'],
            );
        } else {
            $lines[] = 'Last run    : never recorded';
        }

        $changes = $report['changes'];
        if ($changes !== []) {
            $lines[] = '';
            $lines[] = 'Recent changes:';
            foreach (array_slice($changes, 0, 8) as $change) {
                $lines[] = sprintf(
                    '  %s  %s → %s',
                    (string) $change['package'],
                    (string) ($change['from'] ?? '(none)'),
                    (string) $change['to'],
                );
            }
        }

        $notes = $report['notes'];
        if ($notes !== []) {
            $lines[] = '';
            $lines[] = "What's new:";
            foreach (array_slice($notes, 0, 3) as $note) {
                $lines[] = sprintf('  %s %s', (string) $note['package'], (string) $note['version']);
                foreach (array_slice(explode("\n", (string) $note['body']), 0, 6) as $bodyLine) {
                    $lines[] = '    ' . $bodyLine;
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Full view: say "open updates" for the Updates dialog.';

        return implode("\n", $lines);
    }
}
