<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Support\ProjectRoot;

/**
 * The registry of OPEN dialog windows (UI-skills) for the session — the data
 * behind the Focus zone. A dialog is a persistent interactive surface raised by
 * a UI-skill; Focus shows the running ones (normal / minimized / maximized) and,
 * when all are minimized, just their icons.
 *
 * Persisted under `var/os/dialogs.json` so the running set survives restarts
 * (ties to the Awakening State Snapshot). Local-first / single-user: a plain
 * file, no DB. Best-effort — never let dialog bookkeeping break the loop.
 *
 * @phpstan-type Dialog array{id:string,skill:string,title:string,icon:?string,entry:?string,state:string,parentId:?string,order:int,openedAt:string}
 */
#[AsService]
final class OpenDialogStore
{
    private const MAX_DIALOGS = 24;
    private const STATES = ['normal', 'minimized', 'maximized'];

    /**
     * Open a new dialog and return its descriptor.
     *
     * @return Dialog
     */
    public function open(string $skill, string $title, ?string $icon, ?string $entry, ?string $parentId = null): array
    {
        $dialogs = $this->read();

        $maxOrder = 0;
        foreach ($dialogs as $d) {
            $maxOrder = max($maxOrder, (int) ($d['order'] ?? 0));
        }

        $dialog = [
            'id' => 'dlg_' . bin2hex(random_bytes(5)),
            'skill' => $skill,
            'title' => $title,
            'icon' => $icon,
            'entry' => $entry,
            'state' => 'normal',
            'parentId' => $parentId,
            'order' => $maxOrder + 1,
            'openedAt' => gmdate('c'),
        ];

        $dialogs[] = $dialog;
        if (count($dialogs) > self::MAX_DIALOGS) {
            $dialogs = array_slice($dialogs, -self::MAX_DIALOGS);
        }
        $this->write($dialogs);

        return $dialog;
    }

    /**
     * @return list<Dialog>
     */
    public function list(): array
    {
        return array_values($this->read());
    }

    /**
     * Change a dialog's window state (normal/minimized/maximized).
     *
     * @return Dialog|null the updated dialog, or null if not found / invalid state
     */
    public function setState(string $id, string $state): ?array
    {
        if (!in_array($state, self::STATES, true)) {
            return null;
        }

        $dialogs = $this->read();
        $updated = null;
        foreach ($dialogs as $i => $d) {
            if (($d['id'] ?? null) === $id) {
                $dialogs[$i]['state'] = $state;
                $updated = $dialogs[$i];
                break;
            }
        }
        if ($updated === null) {
            return null;
        }
        $this->write($dialogs);

        return $updated;
    }

    /**
     * Close a dialog and any child dialogs it opened.
     */
    public function close(string $id): void
    {
        $dialogs = $this->read();
        $remaining = array_values(array_filter(
            $dialogs,
            static fn (array $d): bool => ($d['id'] ?? null) !== $id && ($d['parentId'] ?? null) !== $id,
        ));
        $this->write($remaining);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data['dialogs'] ?? null) ? array_values($data['dialogs']) : [];
    }

    /**
     * @param list<array<string, mixed>> $dialogs
     */
    private function write(array $dialogs): void
    {
        $file = $this->file();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $file,
            (string) json_encode(['dialogs' => $dialogs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }

    private function file(): string
    {
        return ProjectRoot::get() . '/var/os/dialogs.json';
    }
}
