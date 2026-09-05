<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\PlatformUi\Domain\Model\CalendarEvent;
use Semitexa\PlatformUi\Application\Db\MySQL\Repository\CalendarEventDbRepository;

/**
 * Create a calendar event by talking to the OS ("schedule a meeting tomorrow at
 * 3pm"). The planner extracts a short title + a date/time phrase; this parses
 * the phrase and writes the event through the platform calendar repository,
 * which auto-publishes so any open calendar updates live. Low-risk and
 * deletable, so it runs without a gate.
 *
 * Note: the date phrase is parsed in the server timezone for now — full
 * per-user timezone awareness is a deferred calendar-preferences slice.
 */
#[AsAiSkill(
    name: 'calendar-create',
    summary: 'Add an event to the calendar.',
    useWhen: 'The user wants to schedule / add / create / plan an event, meeting, reminder or appointment (e.g. "schedule a meeting tomorrow at 3pm", "add dentist Friday 10am", "заплануй зустріч завтра о 13:00"). Put a short title in `title`. IMPORTANT: resolve the date/time to an ABSOLUTE value using the current date and pass it in `when` as `YYYY-MM-DD HH:MM` (24-hour), e.g. "2026-07-15 14:00" — never a relative word like "tomorrow"/"завтра" and never another language. Optionally `end` (same absolute format) and `all_day` ("1" for an all-day event).',
    avoidWhen: 'The user only wants to VIEW the calendar (use Calendar) or ASK what is scheduled (use calendar-lookup).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['title', 'when', 'end', 'all_day'],
    argumentHints: [
        'title' => 'Short event title only (e.g. "Dentist").',
        'when' => 'ABSOLUTE start "YYYY-MM-DD HH:MM" (24h), resolved from the current date — never "tomorrow"/"завтра".',
        'end' => 'Optional ABSOLUTE end, same format as when.',
        'all_day' => '"1" for an all-day event; omit otherwise.',
    ],
    channels: ['web'],
)]
final class CalendarCreateSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        if ($title === '') {
            return 'What should I call the event?';
        }

        // The user speaks in their local wall-clock ("13:00"); the server runs in
        // UTC and the calendar stores UTC instants (matching UI-created events),
        // so we parse in the OS timezone, then convert to UTC for storage.
        $tz = (new OsPreferences())->timezone();

        $start = $this->parse(trim((string) ($arguments['when'] ?? '')), $tz);
        if ($start === null) {
            return 'When should I schedule "' . $title . '"? (e.g. "tomorrow 3pm" or "Friday 10:00")';
        }

        $allDayArg = strtolower(trim((string) ($arguments['all_day'] ?? '')));
        $allDay = $allDayArg === '1' || $allDayArg === 'true' || $allDayArg === 'yes';

        $end = $this->parse(trim((string) ($arguments['end'] ?? '')), $tz)
            ?? ($allDay ? $start->setTime(23, 59, 0) : $start->modify('+1 hour'));
        if ($end < $start) {
            $end = $allDay ? $start->setTime(23, 59, 0) : $start->modify('+1 hour');
        }

        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        (new CalendarEventDbRepository())->insert(new CalendarEvent(
            id: Uuid7::generate(),
            tenantId: null,
            userId: null,
            title: $title,
            startsAt: $start->setTimezone($utc),
            endsAt: $end->setTimezone($utc),
            allDay: $allDay,
            location: null,
            notes: null,
            color: 'blue',
            createdAt: $now,
            updatedAt: $now,
        ));

        // Confirm in the user's local time (what they asked for), not UTC.
        $when = $allDay ? $start->format('D M j') : $start->format('D M j, H:i');

        return 'Added "' . $title . '" to your calendar for ' . $when . '.';
    }

    private function parse(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Preferred path: the planner passes an absolute "YYYY-MM-DD HH:MM"
        // (see useWhen), which DateTimeImmutable parses natively below.
        //
        // Safety net: if it still passes a natural phrase, normalise it. English
        // fillers ("friday at 10am") choke PHP's parser, so strip them and glue
        // "10 am" → "10am"; Ukrainian relative words ("завтра о 13:00") aren't
        // understood at all, so translate the day part to an English anchor.
        $candidates = [$value];

        $norm = (string) preg_replace('/\b(at|on)\b/i', ' ', $value);
        $norm = (string) preg_replace('/(\d)\s*(am|pm)\b/i', '$1$2', $norm);
        $norm = trim((string) preg_replace('/\s+/', ' ', $norm));
        if ($norm !== '' && $norm !== $value) {
            $candidates[] = $norm;
        }

        $uk = [
            'післязавтра' => '+2 days ',
            'завтра'      => 'tomorrow ',
            'сьогодні'    => 'today ',
        ];
        $lower = mb_strtolower($value);
        foreach ($uk as $word => $anchor) {
            if (mb_strpos($lower, $word) !== false) {
                $rest = str_ireplace([$word, 'о', 'на', 'в'], ' ', $lower);
                $rest = (string) preg_replace('/[.\s]+/u', ' ', $rest);
                $rest = trim((string) preg_replace('/(\d{1,2})\s+(\d{2})/', '$1:$2', $rest));
                $candidates[] = trim($anchor . $rest);
                break;
            }
        }

        foreach ($candidates as $candidate) {
            try {
                // Interpret the wall-clock in the OS timezone; an explicit offset
                // in the string (e.g. "...Z") still wins, as PHP intends.
                return new \DateTimeImmutable($candidate, $tz);
            } catch (\Throwable) {
                // try the next candidate
            }
        }

        return null;
    }
}
