<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Application\Service\SkillRegistry;

/**
 * The first non-command Semitexa OS skill: a read-only status report that runs
 * on the `web` channel with no `#[AsCommand]` behind it — proving the lifted
 * console-only constraint (concept §9.1). Reports live facts from the Skill
 * Registry; safe and reversible, so it executes without confirmation.
 */
#[AsAiSkill(
    name: 'os:status',
    summary: 'Report live Semitexa OS status — registered skills and runtime time.',
    useWhen: 'The user asks about OS status, how many skills exist, or what is running.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['web'],
)]
final class OsStatusSkill implements InvocableSkillInterface
{
    public function invoke(array $arguments): string
    {
        $manifest = (new SkillRegistry())->buildManifest();
        $skills = $manifest->skills;
        $webSkills = array_filter($skills, static fn ($s): bool => in_array('web', $s->channels, true));

        return implode("\n", [
            'Semitexa OS — live status',
            '─────────────────────────',
            'Skills registered : ' . count($skills),
            'Web-channel skills: ' . count($webSkills),
            'Time (UTC)        : ' . gmdate('Y-m-d H:i:s'),
            'Channel           : web (invocable, no console command)',
        ]);
    }
}
