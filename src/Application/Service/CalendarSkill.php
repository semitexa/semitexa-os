<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The Calendar UI-skill: opens the live `platform.calendar` component as a
 * dialog in Focus (entry route `/os/app/calendar`). Raised on demand when the
 * user wants to see or plan their schedule.
 */
#[AsAiSkill(
    name: 'Calendar',
    summary: 'Open the calendar to see and plan events on specific days.',
    useWhen: 'The user wants to open the calendar, see their month/schedule, plan their day, or add/edit an event on a specific day.',
    avoidWhen: 'The user only asks WHAT is on their schedule without wanting the UI — use calendar-lookup for that.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'calendar',
    entry: '/os/app/calendar',
)]
final class CalendarSkill
{
}
