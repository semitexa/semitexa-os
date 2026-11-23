<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Domain\Model\SkillEntry;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Application\Service\TenantSkillScope;

/**
 * The first non-command Semitexa OS skill: a read-only status report that runs
 * on the `web` channel with no `#[AsCommand]` behind it — proving the lifted
 * console-only constraint (concept §9.1). Reports live facts from the Skill
 * Registry; safe and reversible, so it executes without confirmation.
 *
 * The count is the caller's: the skills their scope owns on the console's
 * channels, less what their role is refused. It counted the whole install's
 * registry, so a site's admin read how many skills other sites and the
 * operator have.
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
    #[InjectAsReadonly]
    protected OsSkillScope $scope;

    #[InjectAsReadonly]
    protected TenantSkillScope $scopes;

    #[InjectAsReadonly]
    protected OsConsoleAccess $access;

    public function invoke(array $arguments): string
    {
        $skills = $this->skills();
        $webSkills = array_filter($skills, static fn (SkillEntry $s): bool => in_array('web', $s->channels, true));

        return implode("\n", [
            'Semitexa OS — live status',
            '─────────────────────────',
            'Skills registered : ' . count($skills),
            'Web-channel skills: ' . count($webSkills),
            'Time (UTC)        : ' . gmdate('Y-m-d H:i:s'),
            'Channel           : web (invocable, no console command)',
        ]);
    }

    /**
     * What the shell lists and the runner runs for this person; nothing when
     * nobody is signed in.
     *
     * @return list<SkillEntry>
     */
    private function skills(): array
    {
        $scope = isset($this->scope) ? $this->scope->forCurrentRequest() : null;
        if ($scope === null || !isset($this->scopes)) {
            return [];
        }

        $manifest = $this->scopes->manifestFor($scope, SkillLoopRunner::OS_CHANNELS);

        return (isset($this->access) ? $this->access->narrow($manifest) : $manifest)->skills();
    }
}
