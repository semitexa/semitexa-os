<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Os\Domain\Enum\IntentDecision;
use Semitexa\Os\Domain\Model\IntentOutcome;
use Semitexa\Platform\Settings\Application\Service\SettingsStore;
use Semitexa\Platform\Settings\Domain\Contract\SettingsStoreInterface;

/**
 * Durable, OS-owned session log — the substrate for the Awakening "State
 * Snapshot" (concept §2). A small ring buffer of recent meaningful actions
 * persisted in the DB-backed settings store (module 'os', key 'session', like
 * {@see SkinStore}/{@see OsPreferences}) so "continue where you were" survives
 * restarts AND stays coherent across Swoole workers. No semitexa-dev trace
 * dependency (that store is agent epic/task traces, not the OS session).
 */
#[AsService]
final class OsSessionStore
{
    private const MAX_EVENTS = 50;
    private const MODULE = 'os';
    private const KEY = 'session';

    #[InjectAsReadonly]
    protected SettingsStoreInterface $settings;

    /**
     * Record a terminal, meaningful outcome (a skill ran or the assistant
     * answered). Best-effort — never let session logging break the loop.
     */
    public function record(IntentOutcome $outcome): void
    {
        if (!in_array($outcome->decision, [IntentDecision::Executed, IntentDecision::Answer, IntentDecision::OpenDialog], true)) {
            return;
        }

        $event = [
            'at' => gmdate('c'),
            'intent' => $outcome->intent,
            'decision' => $outcome->decision->value,
            'skill' => $outcome->skill,
            'pipeline' => array_values(array_map(
                static fn (array $s): string => (string) ($s['skill'] ?? ''),
                $outcome->pipeline,
            )),
            'exit_code' => $outcome->exitCode,
        ];

        try {
            $events = $this->read();
            $events[] = $event;
            if (count($events) > self::MAX_EVENTS) {
                $events = array_slice($events, -self::MAX_EVENTS);
            }
            $this->write($events);
        } catch (\Throwable) {
            // best-effort persistence
        }
    }

    /**
     * The State Snapshot the Awakening screen restores.
     *
     * @return array{count: int, last_at: ?string, last_intent: ?string, last_skill: ?string, recent: list<array{intent: string, skill: ?string, decision: string}>}
     */
    public function snapshot(): array
    {
        $events = $this->read();
        $count = count($events);
        $last = $count > 0 ? $events[$count - 1] : null;

        $lastSkill = null;
        for ($i = $count - 1; $i >= 0; $i--) {
            if (($events[$i]['decision'] ?? '') === 'executed') {
                $lastSkill = $events[$i]['skill'] ?? ($events[$i]['pipeline'][0] ?? null);
                break;
            }
        }

        $recent = array_map(
            static fn (array $e): array => [
                'intent' => (string) ($e['intent'] ?? ''),
                'skill' => $e['skill'] ?? null,
                'decision' => (string) ($e['decision'] ?? ''),
            ],
            array_slice($events, -5),
        );

        return [
            'count' => $count,
            'last_at' => $last['at'] ?? null,
            'last_intent' => $last['intent'] ?? null,
            'last_skill' => $lastSkill,
            'recent' => array_values($recent),
        ];
    }

    /**
     * The full recorded event log (oldest → newest), for consumers that need
     * more than the {@see self::snapshot()} summary — e.g. the
     * {@see SuggestionEngine} mining the user's own past intents.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return $this->read();
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

        return is_array($data['events'] ?? null) ? array_values($data['events']) : [];
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function write(array $events): void
    {
        $this->settings()->set(self::MODULE, self::KEY, (string) json_encode(
            ['events' => $events],
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
