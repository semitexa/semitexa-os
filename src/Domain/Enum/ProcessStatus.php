<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Enum;

/**
 * Lifecycle of one reported process in the OS process registry (`os_process`).
 *
 * `Stalled` is registry-assigned, never producer-reported: a running process
 * whose heartbeat (updated_at) goes silent past the TTL is demoted on read —
 * a progress bar must never keep animating for work that died.
 */
enum ProcessStatus: string
{
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Stalled = 'stalled';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Running',
            self::Done => 'Done',
            self::Failed => 'Failed',
            self::Stalled => 'Stalled',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
