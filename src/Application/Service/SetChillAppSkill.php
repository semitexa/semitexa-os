<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Change (or forget) the app behind a Chill leisure chip by talking to the OS
 * ("зміни музичний додаток", "use YouTube for music", "не подобається цей
 * плеєр"). The chip remembers the user's first choice and opens it directly
 * ({@see OsPreferences::chillApps()}); this skill is the escape hatch — name a
 * replacement to switch, or name none and the next chip press asks the
 * assistant for options again.
 */
#[AsAiSkill(
    name: 'set-chill-app',
    summary: 'Change or reset which app a Chill chip (music / video / game) opens.',
    useWhen: 'The user wants a DIFFERENT app for a leisure activity, or dislikes the remembered one — e.g. "зміни музичний додаток", "хочу інший плеєр", "use YouTube for music", "постав іншу гру". Put the activity (music|video|game) in `activity`; put the wanted app in `app` if the user NAMED one, else leave `app` empty.',
    avoidWhen: 'The user just wants to LISTEN/WATCH/PLAY right now (route the leisure intent itself), or asks about OS settings unrelated to leisure apps.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['activity', 'app'],
    argumentHints: [
        'activity' => 'One of: music|video|game.',
        'app' => 'The app the user asked for, if any — a skill/app name like "music", "youtube", "tic-tac-toe". Empty when they only want a change.',
    ],
    channels: ['web'],
)]
final class SetChillAppSkill implements InvocableSkillInterface
{
    /** Tenant-aware via container injection (SkillLoopRunner's DI resolver). */
    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    public function invoke(array $arguments): string
    {
        $activity = $this->normaliseActivity((string) ($arguments['activity'] ?? ''));
        if ($activity === null) {
            return 'Which one should I change — music, video, or the game?';
        }

        $wanted = trim((string) ($arguments['app'] ?? ''));
        if ($wanted === '') {
            try {
                $this->prefs()->setChillApp($activity, '');
            } catch (\InvalidArgumentException $e) {
                return $e->getMessage();
            }

            return 'Done — forgot the ' . $activity . ' app. Press the chip (or just ask) and I\'ll suggest options; your next pick sticks.';
        }

        $resolved = $this->resolveSkill($wanted);
        if ($resolved === null) {
            return 'I don\'t know an app called "' . $wanted . '". Press the chip and tell me what you\'d like — your next pick sticks.';
        }

        try {
            $this->prefs()->setChillApp($activity, $resolved['skill']);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return 'Done — the ' . $activity . ' chip now opens ' . $resolved['label'] . '.';
    }

    /** Map loose phrasing to a chip activity, or null if none recognised. */
    private function normaliseActivity(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        if (in_array($v, ['music', 'video', 'game'], true)) {
            return $v;
        }
        if (str_contains($v, 'муз') || str_contains($v, 'плеєр') || str_contains($v, 'плеер') || str_contains($v, 'player') || str_contains($v, 'audio')) {
            return 'music';
        }
        if (str_contains($v, 'віде') || str_contains($v, 'video') || str_contains($v, 'youtube') || str_contains($v, 'фільм') || str_contains($v, 'кіно')) {
            return 'video';
        }
        if (str_contains($v, 'гр') || str_contains($v, 'game') || str_contains($v, 'ігр')) {
            return 'game';
        }

        return null;
    }

    /**
     * Resolve the user's app name against the discovered skill set (UI-skills
     * open as dialogs, so only they make sense behind a chip). Case-insensitive
     * contains-match on the skill name, so "youtube"/"ютуб" style loose names
     * land; null when nothing matches.
     *
     * @return array{skill: string, label: string}|null
     */
    private function resolveSkill(string $wanted): ?array
    {
        $needle = mb_strtolower($wanted);
        $aliases = ['ютуб' => 'youtube', 'плеєр' => 'music', 'плеер' => 'music', 'гра' => 'tic-tac-toe'];
        $needle = $aliases[$needle] ?? $needle;

        try {
            $manifest = (new SkillRegistry())->buildManifest();
        } catch (\Throwable) {
            return null;
        }
        foreach ($manifest->skills as $skill) {
            if (!$skill->isUi()) {
                continue;
            }
            $name = mb_strtolower($skill->name);
            if ($name === $needle || str_contains($name, $needle) || str_contains($needle, $name)) {
                return ['skill' => $skill->name, 'label' => $skill->name];
            }
        }

        // Registered web apps open as dialogs too (skill id 'webapp:<id>') —
        // "use YouTube for music" should land on the wrapped site. Soft dep:
        // installs without semitexa-webapps just skip this leg.
        if (class_exists(\Semitexa\WebApps\Application\Service\WebAppStore::class)) {
            try {
                foreach ((new \Semitexa\WebApps\Application\Service\WebAppStore())->all() as $app) {
                    $name = mb_strtolower((string) ($app['name'] ?? ''));
                    $host = mb_strtolower((string) ($app['host'] ?? ''));
                    if ($name === '' || !isset($app['id'])) {
                        continue;
                    }
                    if (str_contains($name, $needle) || str_contains($needle, $name) || str_contains($host, $needle)) {
                        return ['skill' => 'webapp:' . $app['id'], 'label' => (string) $app['name']];
                    }
                }
            } catch (\Throwable) {
                // Registry unavailable — fall through to "unknown app".
            }
        }

        return null;
    }

    private function prefs(): OsPreferences
    {
        return isset($this->prefs) ? $this->prefs : new OsPreferences();
    }
}
