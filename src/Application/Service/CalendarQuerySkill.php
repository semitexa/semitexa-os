<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\PlatformUi\Application\Db\MySQL\Repository\CalendarEventDbRepository;

/**
 * Gives the OS planner calendar awareness: reads events from the platform
 * calendar store for a requested window and returns a concise text summary, so
 * intents like "what's on my schedule tomorrow?" or "am I free Friday?"
 * resolve. Read-only; the Calendar UI-skill is for opening/editing.
 */
#[AsAiSkill(
    name: 'calendar-lookup',
    summary: "Report what's on the user's calendar for a day or range.",
    useWhen: 'The user asks what is on their schedule/calendar, whether they are free, or about upcoming events (e.g. "what\'s on my schedule tomorrow?", "am I free Friday?", "what\'s coming up?"). Put the day/range in the `when` argument (e.g. "today", "tomorrow", "this week", "2026-07-15").',
    avoidWhen: 'The user wants to open the calendar UI or add an event — use the Calendar skill for that.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['when'],
    argumentHints: [
        'when' => 'Day or range: "today", "tomorrow", "this week", or "YYYY-MM-DD".',
    ],
    channels: ['web'],
)]
final class CalendarQuerySkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $when = strtolower(trim((string) ($arguments['when'] ?? '')));

        // Events are stored as UTC instants; the user thinks in local wall-clock,
        // so resolve the window in the OS timezone and display times in it too.
        $tz = (new OsPreferences())->timezone();
        [$from, $to, $label] = $this->resolveWindow($when, $tz);

        $utc = new \DateTimeZone('UTC');
        $events = (new CalendarEventDbRepository())->findInRange($from->setTimezone($utc), $to->setTimezone($utc), null);

        if ($events === []) {
            return 'Nothing on your calendar ' . $label . '.';
        }

        $lines = [];
        foreach ($events as $e) {
            $start = $e->starts_at->setTimezone($tz);
            $time = $e->all_day === 1 ? 'all day' : $start->format('H:i');
            $where = $e->location !== null && $e->location !== '' ? ' (' . $e->location . ')' : '';
            $lines[] = '• ' . $start->format('D M j') . ', ' . $time . ' — ' . $e->title . $where;
        }

        $count = count($events);

        return 'You have ' . $count . ' event' . ($count === 1 ? '' : 's') . ' ' . $label . ":\n" . implode("\n", $lines);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}
     */
    private function resolveWindow(string $when, \DateTimeZone $tz): array
    {
        $startOfToday = new \DateTimeImmutable('today 00:00:00', $tz);
        $endOf = static fn (\DateTimeImmutable $d): \DateTimeImmutable => $d->setTime(23, 59, 59);

        if ($when === '' || $when === 'today') {
            return [$startOfToday, $endOf($startOfToday), 'today'];
        }
        if ($when === 'tomorrow') {
            $d = $startOfToday->modify('+1 day');

            return [$d, $endOf($d), 'tomorrow'];
        }
        if ($when === 'this week' || $when === 'week' || $when === 'upcoming' || $when === 'next 7 days') {
            return [$startOfToday, $endOf($startOfToday->modify('+6 days')), 'in the next 7 days'];
        }

        // A concrete day the planner passed ("2026-07-15", "friday", "next monday").
        try {
            $d = (new \DateTimeImmutable($when, $tz))->setTime(0, 0);

            return [$d, $endOf($d), 'on ' . $d->format('D M j')];
        } catch (\Throwable) {
            return [$startOfToday, $endOf($startOfToday->modify('+6 days')), 'in the next 7 days'];
        }
    }
}
