<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;

/**
 * Read-only aggregation behind the OS "Updates / What's new" surface: current
 * release set, last update run, run history, applied version changes, and
 * their release notes. Data comes from the semitexa/update run journal and
 * per-package CHANGELOG.md files.
 *
 * semitexa/update is a soft dependency: when the package is not installed the
 * report degrades to `available: false` instead of failing — the OS shell
 * stays usable on stripped-down installs.
 */
final class UpdatesReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(int $historyLimit = 10): array
    {
        if (!class_exists(\Semitexa\Update\Application\Service\RunJournalRepository::class)) {
            return ['available' => false];
        }

        $projectRoot = ProjectRoot::get();

        $report = [
            'available'       => true,
            'updater_version' => \Semitexa\Update\Application\Service\UpdateRunnerFactory::installedUpdaterVersion(),
            'packages'        => 0,
            'release_set'     => null,
            'last_run'        => null,
            'runs'            => [],
            'changes'         => [],
            'notes'           => [],
        ];

        $installed = (new \Semitexa\Update\Application\Service\Packaging\Releases\Support\InstalledSemitexaPackageReader())
            ->read($projectRoot);
        $report['packages'] = count($installed);
        if ($installed !== []) {
            $dates = array_values(array_unique(array_map(
                static fn (string $v): string => substr($v, 0, 10),
                $installed,
            )));
            sort($dates);
            $report['release_set'] = count($dates) === 1 ? $dates[0] : 'mixed: ' . implode(', ', $dates);
        }

        try {
            $adapter = (new ConnectionRegistry())->manager('default')->getAdapter();
            $journal = new \Semitexa\Update\Application\Service\RunJournalRepository($adapter);
            $records = $journal->findRecent($historyLimit);
        } catch (\Throwable) {
            $records = [];
        }

        $reader = new \Semitexa\Update\Application\Service\Changelog\PackageChangelogReader($projectRoot);
        $seenDelta = [];
        foreach ($records as $i => $run) {
            $row = [
                'id'           => $run->id,
                'started_at'   => $run->startedAt,
                'kind'         => $run->kind,
                'actor'        => $run->actor,
                'outcome'      => $run->outcome->value,
                'failed_stage' => $run->failedStage,
                'deltas'       => count($run->packageDeltas),
                'patches'      => $run->patchesApplied,
                'duration_ms'  => $run->durationMs,
            ];
            $report['runs'][] = $row;
            if ($i === 0) {
                $report['last_run'] = $row;
            }

            foreach ($run->packageDeltas as $package => $delta) {
                $from = isset($delta['from']) ? (string) $delta['from'] : null;
                $to = (string) ($delta['to'] ?? '?');
                $key = $package . '|' . $from . '|' . $to;
                if (isset($seenDelta[$key])) {
                    continue;
                }
                $seenDelta[$key] = true;

                $report['changes'][] = [
                    'at'      => $run->completedAt ?? $run->startedAt,
                    'package' => (string) $package,
                    'from'    => $from,
                    'to'      => $to,
                    'outcome' => $run->outcome->value,
                ];

                foreach ($reader->notesBetween((string) $package, $from, $to) as $note) {
                    $report['notes'][] = [
                        'package' => $note->package,
                        'version' => $note->version,
                        'date'    => $note->date,
                        'body'    => $note->body,
                    ];
                }
            }
        }

        return $report;
    }
}
