<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * The registry of OPEN dialog windows (UI-skills) for the session — the data
 * behind the Focus zone. A dialog is a persistent interactive surface raised by
 * a UI-skill; Focus shows the running ones (normal / minimized / maximized) and,
 * when all are minimized, just their icons.
 *
 * Persisted in the DB-backed settings store (module 'os', key 'dialogs', like
 * {@see SkinStore}/{@see OsSessionStore}) so the running set survives restarts
 * (ties to the Awakening State Snapshot) AND stays coherent across Swoole
 * workers. Best-effort — never let dialog bookkeeping break the loop.
 *
 * @phpstan-type Dialog array{id:string,skill:string,title:string,icon:?string,entry:?string,state:string,parentId:?string,order:int,openedAt:string}
 */
#[AsService]
final class OpenDialogStore
{
    private const MAX_DIALOGS = 24;
    private const STATES = ['normal', 'minimized', 'maximized'];
    private const MODULE = 'os';
    private const KEY = 'dialogs';

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

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
        $raw = $this->settings()->get(self::MODULE, self::KEY);
        if (!is_string($raw) || $raw === '') {
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
        $this->settings()->set(self::MODULE, self::KEY, (string) json_encode(
            ['dialogs' => $dialogs],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function settings(): SettingsStoreInterface
    {
        if (!isset($this->settings)) {
            $this->settings = new SettingsStore();
        }

        return $this->settings;
    }
}
