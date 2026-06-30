<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Application as ConsoleApplication;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Application\Service\Planner;
use Semitexa\Llm\Application\Service\SkillExecutor;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\PlannerResponseType;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\PlannerResponse;
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Os\Domain\Enum\IntentDecision;
use Semitexa\Os\Domain\Model\IntentOutcome;

/**
 * Drives one user intent through the Semitexa OS Skill loop:
 * Intent -> Plan ({@see Planner} over the {@see SkillManifest}) -> Execute
 * ({@see SkillExecutor}) -> Observe ({@see IntentOutcome}).
 *
 * This is the generalisation of `semitexa-llm`'s console REPL
 * ({@see \Semitexa\Llm\Application\Console\Command\AiAssistantCommand}) beyond the
 * terminal: it reuses the exact same Planner / SkillRegistry / SkillExecutor, but
 * obtains the Symfony Console Application from {@see ConsoleApplication} (which
 * self-populates all `#[AsCommand]` skills from the worker container) instead of
 * the running console process. See the spike verdict in
 * `ep-os-skill-loop-web-shell-v0 / tk-os-spike-llm-under-swoole`.
 *
 * v0 scope: single skill per intent (no orchestration), skills run on the
 * `console` channel, no conversation history.
 */
#[AsService]
final class SkillLoopRunner
{
    /**
     * Canonical env-aware selector (honours `LLM_BACKEND`). We route through it
     * rather than injecting `LlmProviderInterface` directly, because a bare
     * injection of a factory-keyed contract resolves to the priority-default
     * implementation and ignores `LLM_BACKEND`.
     */
    #[InjectAsReadonly]
    protected LlmProviderResolver $providers;

    /** Durable OS-session log behind the Awakening State Snapshot. */
    #[InjectAsReadonly]
    protected OsSessionStore $session;

    /** Registry of open dialog windows (Focus zone) for UI-skills. */
    #[InjectAsReadonly]
    protected OpenDialogStore $dialogs;

    /**
     * Built once and cached for the lifetime of this (worker-scoped) service:
     * instantiating every `#[AsCommand]` is not free, so we never rebuild per request.
     */
    private ?ConsoleApplication $consoleApp = null;

    private ?SkillExecutor $executor = null;

    /**
     * The active LLM provider for this runtime, selected by `LLM_BACKEND`.
     */
    public function provider(): LlmProviderInterface
    {
        return $this->providers->provider();
    }

    /**
     * Plan an intent and, when policy allows, execute the proposed skill.
     *
     * A skill whose {@see AiConfirmationMode} is not `Never` is NOT executed here;
     * it returns {@see IntentDecision::NeedsConfirmation} so the Observe surface can
     * ask the user, then call {@see self::approveAndExecute()}.
     */
    public function run(string $intent): IntentOutcome
    {
        $intent = trim($intent);
        if ($intent === '') {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::Error,
                error: 'Empty intent.',
            );
        }

        if (!$this->provider()->healthCheck()) {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::Error,
                error: 'LLM provider is unreachable at ' . $this->provider()->baseUrl()
                    . '. Ensure Ollama (or your LLM runtime) is running.',
                providerName: $this->provider()->name(),
                providerModel: $this->provider()->model(),
            );
        }

        $manifest = $this->manifest();
        $planner = new Planner();

        $request = new LlmRequest(
            systemPrompt: $planner->buildSystemPrompt($manifest),
            userMessage: $intent,
            history: [],
        );

        $plannerResponse = $planner->parseResponse($this->provider()->complete($request));

        $outcome = match ($plannerResponse->type) {
            PlannerResponseType::Answer => $this->observe($intent, IntentDecision::Answer, $plannerResponse),
            PlannerResponseType::Ask => $this->observe($intent, IntentDecision::Ask, $plannerResponse),
            PlannerResponseType::Refuse => $this->observe($intent, IntentDecision::Refuse, $plannerResponse),
            PlannerResponseType::ProposeSkill => $this->handleProposal($intent, $plannerResponse, $manifest),
            PlannerResponseType::ProposePipeline => $this->handlePipeline($intent, $plannerResponse, $manifest),
        };

        $this->session->record($outcome);

        return $outcome;
    }

    /**
     * Plan a multi-skill pipeline (executionKind: orchestration). Validates every
     * step against the manifest; if any step requires confirmation the whole chain
     * is gated ({@see IntentDecision::NeedsConfirmation}) and {@see self::executePipeline()}
     * is called only after approval; otherwise it runs immediately.
     */
    private function handlePipeline(string $intent, PlannerResponse $response, SkillManifest $manifest): IntentOutcome
    {
        $steps = $response->steps;
        if ($steps === []) {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::Error,
                reason: $response->reason,
                error: 'Planner proposed a pipeline with no steps.',
            );
        }

        $needsConfirmation = false;
        foreach ($steps as $step) {
            $entry = $manifest->findSkill($step['skill']);
            if ($entry === null) {
                return new IntentOutcome(
                    intent: $intent,
                    decision: IntentDecision::Error,
                    reason: $response->reason,
                    pipeline: $steps,
                    error: "Pipeline references unknown skill '{$step['skill']}' — rejected.",
                );
            }
            if ($entry->confirmation !== AiConfirmationMode::Never) {
                $needsConfirmation = true;
            }
        }

        if ($needsConfirmation) {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::NeedsConfirmation,
                reason: $response->reason,
                skill: $steps[0]['skill'],
                confidence: $response->confidence,
                pipeline: $steps,
                providerName: $this->provider()->name(),
                providerModel: $this->provider()->model(),
            );
        }

        return $this->executePipeline($intent, $steps);
    }

    /**
     * Execute an approved skill chain in order, stopping on the first failure,
     * and return a single combined {@see IntentOutcome} (the Observe payload).
     *
     * @param list<array{skill: string, arguments: array<string, mixed>}> $steps
     */
    public function executePipeline(string $intent, array $steps): IntentOutcome
    {
        $manifest = $this->manifest();
        $outputs = [];
        $ran = [];
        $lastExit = 0;
        $error = null;

        foreach ($steps as $step) {
            $skill = $step['skill'];
            $entry = $manifest->findSkill($skill);
            if ($entry === null) {
                return new IntentOutcome(
                    intent: $intent,
                    decision: IntentDecision::Error,
                    pipeline: $steps,
                    error: "Unknown skill '{$skill}' in pipeline — rejected.",
                );
            }

            $arguments = $step['arguments'] ?? [];
            $channel = $entry->skillClass !== null ? 'web' : 'console';
            $result = $this->executor()->execute($skill, $arguments, $manifest, $channel);

            $ran[] = ['skill' => $skill, 'arguments' => $arguments];
            $outputs[] = '» ' . $skill . "\n" . trim((string) $result->output);

            if ($result->exitCode !== 0) {
                $lastExit = $result->exitCode;
                $error = $result->error;
                break; // stop the chain on first failure
            }
        }

        $outcome = new IntentOutcome(
            intent: $intent,
            decision: IntentDecision::Executed,
            skill: null,
            exitCode: $lastExit,
            output: implode("\n\n", $outputs),
            error: $error,
            providerName: $this->provider()->name(),
            providerModel: $this->provider()->model(),
            pipeline: $ran,
        );

        $this->session->record($outcome);

        return $outcome;
    }

    /**
     * Execute a skill the user has explicitly approved (the second leg of a
     * {@see IntentDecision::NeedsConfirmation} outcome). The skill name is
     * re-validated against the manifest before running.
     *
     * @param array<string, scalar|null> $arguments
     */
    public function approveAndExecute(string $intent, string $skill, array $arguments): IntentOutcome
    {
        $manifest = $this->manifest();
        $entry = $manifest->findSkill($skill);
        if ($entry === null) {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::Error,
                skill: $skill,
                arguments: $arguments,
                error: "Unknown skill '{$skill}' — rejected.",
            );
        }

        $outcome = $this->execute($intent, $skill, $arguments, $entry->riskLevel->value, null, $manifest);
        $this->session->record($outcome);

        return $outcome;
    }

    private function handleProposal(
        string $intent,
        PlannerResponse $response,
        SkillManifest $manifest,
    ): IntentOutcome {
        $skill = $response->skill;
        if ($skill === null) {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::Error,
                reason: $response->reason,
                error: 'Planner proposed a skill but did not name one.',
            );
        }

        $entry = $manifest->findSkill($skill);
        if ($entry === null) {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::Error,
                skill: $skill,
                arguments: $response->arguments,
                reason: $response->reason,
                error: "Planner proposed unknown skill '{$skill}' — rejected.",
            );
        }

        // UI-skill: open a persistent dialog (Focus) instead of executing.
        if ($entry->isUi()) {
            $this->dialogs->open(
                skill: $entry->name,
                title: $entry->name,
                icon: $entry->icon,
                entry: $entry->entry,
            );

            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::OpenDialog,
                skill: $entry->name,
                reason: $response->reason,
                message: 'Opened ' . $entry->name . ' in Focus.',
                confidence: $response->confidence,
                providerName: $this->provider()->name(),
                providerModel: $this->provider()->model(),
                pipeline: [['skill' => $entry->name, 'arguments' => []]],
            );
        }

        $needsConfirmation = $entry->confirmation !== AiConfirmationMode::Never;
        if ($needsConfirmation) {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::NeedsConfirmation,
                reason: $response->reason,
                skill: $skill,
                arguments: $response->arguments,
                riskLevel: $entry->riskLevel->value,
                confidence: $response->confidence,
                providerName: $this->provider()->name(),
                providerModel: $this->provider()->model(),
            );
        }

        return $this->execute(
            $intent,
            $skill,
            $response->arguments,
            $entry->riskLevel->value,
            $response->confidence,
            $manifest,
        );
    }

    /**
     * @param array<string, scalar|null> $arguments
     */
    private function execute(
        string $intent,
        string $skill,
        array $arguments,
        ?string $riskLevel,
        ?float $confidence,
        SkillManifest $manifest,
    ): IntentOutcome {
        // Invocable (non-command) skills run on the 'web' channel; command skills on 'console'.
        $entry = $manifest->findSkill($skill);
        $channel = ($entry !== null && $entry->skillClass !== null) ? 'web' : 'console';
        $result = $this->executor()->execute($skill, $arguments, $manifest, $channel);

        return new IntentOutcome(
            intent: $intent,
            decision: IntentDecision::Executed,
            skill: $skill,
            arguments: $arguments,
            riskLevel: $riskLevel,
            confidence: $confidence,
            exitCode: $result->exitCode,
            output: $result->output,
            error: $result->error,
            providerName: $this->provider()->name(),
            providerModel: $this->provider()->model(),
        );
    }

    private function observe(
        string $intent,
        IntentDecision $decision,
        PlannerResponse $response,
    ): IntentOutcome {
        return new IntentOutcome(
            intent: $intent,
            decision: $decision,
            message: $response->message ?? $response->reason,
            reason: $response->reason,
            confidence: $response->confidence,
            providerName: $this->provider()->name(),
            providerModel: $this->provider()->model(),
        );
    }

    private function manifest(): SkillManifest
    {
        return (new SkillRegistry())->buildManifest();
    }

    private function executor(): SkillExecutor
    {
        if ($this->executor === null) {
            $this->consoleApp ??= new ConsoleApplication();
            $this->executor = new SkillExecutor($this->consoleApp);
        }

        return $this->executor;
    }
}
