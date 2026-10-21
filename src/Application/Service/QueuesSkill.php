<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The Queues UI-skill: the queues' throughput, failures, wait and run time,
 * what is waiting and what was dead-lettered, as `queue:stats` and
 * `queue:failed` show them in a terminal. Identity + entry only; the body is
 * rendered by `/os/app/queues`.
 */
#[AsAiSkill(
    name: 'Queues',
    summary: 'Open Queues to see how background jobs are doing — throughput, failures, waiting and dead-lettered jobs.',
    useWhen: 'The user asks about queues, background jobs, failed or stuck jobs, or the queue dashboard.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'list',
    entry: '/os/app/queues',
    // The queues hold every tenant's jobs; the handler refuses a tenant admin
    // too, this only keeps the icon out of their launcher.
    operatorOnly: true,
)]
final class QueuesSkill
{
}
