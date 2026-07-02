<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Application as ConsoleApplication;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Application\Service\PersonaRegistry;
use Semitexa\Llm\Application\Service\RemoteOllamaProvider;
use Semitexa\Llm\Application\Service\Planner;
use Semitexa\Llm\Application\Service\SkillExecutor;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\PlannerResponseType;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\PlannerResponse;
use Semitexa\Llm\Domain\Model\SkillEntry;
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

    /** The full dialog transcript (both sides, every turn). */
    #[InjectAsReadonly]
    protected ConversationStore $conversation;

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

        // Persist the user's turn IMMEDIATELY — before the planner / skill run — so
        // that a worker crash during processing (e.g. a concurrent SSE Redis-read
        // deadlock on the same Swoole worker) can never drop the user's message
        // from history. The assistant reply is still appended once the loop finishes.
        $this->conversation->append(ConversationStore::ROLE_USER, $intent);

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

        $plannerResponse = $planner->parseResponse(
            $this->plannerProvider()->complete($this->plannerRequest($intent, $manifest)),
        );

        $outcome = match ($plannerResponse->type) {
            PlannerResponseType::Answer => $this->observe($intent, IntentDecision::Answer, $plannerResponse),
            PlannerResponseType::Ask => $this->observe($intent, IntentDecision::Ask, $plannerResponse),
            PlannerResponseType::Refuse => $this->observe($intent, IntentDecision::Refuse, $plannerResponse),
            PlannerResponseType::ProposeSkill => $this->handleProposal($intent, $plannerResponse, $manifest),
            PlannerResponseType::ProposePipeline => $this->handlePipeline($intent, $plannerResponse, $manifest),
        };

        $this->session->record($outcome);
        // User turn already persisted at the top of the loop (crash-safe); append the reply.
        $this->conversation->append(ConversationStore::ROLE_ASSISTANT, $outcome->displayText(), $this->turnMeta($outcome));

        return $outcome;
    }

    /**
     * Compact per-turn metadata stored alongside an assistant transcript line.
     *
     * @return array<string, mixed>
     */
    private function turnMeta(IntentOutcome $outcome): array
    {
        return [
            'decision' => $outcome->decision->value,
            'skill' => $outcome->skill,
            'surface' => $outcome->surface()->value,
        ];
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
        $firstUi = null;
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
            if ($firstUi === null && $entry->isUi()) {
                $firstUi = $entry;
            }
            if ($entry->confirmation !== AiConfirmationMode::Never) {
                $needsConfirmation = true;
            }
        }

        // A pipeline can't execute UI-skills — they raise a dialog, not console
        // output. "I want to write several notes" plans as [Notes, Notes]; we
        // route the first UI-skill through the single dialog path, which opens
        // one dialog (or asks, if one is already running) rather than spawning a
        // duplicate per step.
        if ($firstUi !== null) {
            return $this->handleUiSkill($intent, $firstUi, $response->reason, $response->confidence);
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

        // run() records the conversation turn for this intent, so the internal
        // call must not (else the executed pipeline turn would be logged twice).
        return $this->executePipeline($intent, $steps, recordTurn: false);
    }

    /**
     * Execute an approved skill chain in order, stopping on the first failure,
     * and return a single combined {@see IntentOutcome} (the Observe payload).
     *
     * @param list<array{skill: string, arguments: array<string, mixed>}> $steps
     * @param bool $recordTurn whether to log this as its own conversation/session
     *                         turn — false when {@see self::run()} already records
     *                         the turn for the same intent (internal call)
     */
    public function executePipeline(string $intent, array $steps, bool $recordTurn = true): IntentOutcome
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
            $result = $this->executor()->execute($skill, $arguments, $manifest, $this->channelFor($entry));

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

        if ($recordTurn) {
            $this->session->record($outcome);
            $this->conversation->append(ConversationStore::ROLE_USER, $intent);
            $this->conversation->append(ConversationStore::ROLE_ASSISTANT, $outcome->displayText(), $this->turnMeta($outcome));
        }

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
        // The user's intent + the proposal were recorded when the gate was
        // raised; here we append only the executed result of the approval.
        $this->conversation->append(ConversationStore::ROLE_ASSISTANT, $outcome->displayText(), $this->turnMeta($outcome));

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
            return $this->handleUiSkill($intent, $entry, $response->reason, $response->confidence);
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
     * Raise a UI-skill as a dialog window (Focus) — but never silently
     * duplicate one. If a dialog for this skill is already running, the OS asks
     * instead ({@see IntentDecision::DialogExists}): open another, or switch to
     * the running one. Only when none is open do we open a fresh dialog.
     */
    private function handleUiSkill(
        string $intent,
        SkillEntry $entry,
        ?string $reason,
        ?float $confidence,
    ): IntentOutcome {
        foreach ($this->dialogs->list() as $dialog) {
            if (($dialog['skill'] ?? null) === $entry->name) {
                return new IntentOutcome(
                    intent: $intent,
                    decision: IntentDecision::DialogExists,
                    skill: $entry->name,
                    reason: $reason,
                    message: $entry->name . ' is already open. Open another, or switch to the one in Focus?',
                    confidence: $confidence,
                    providerName: $this->provider()->name(),
                    providerModel: $this->provider()->model(),
                );
            }
        }

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
            reason: $reason,
            message: 'Opened ' . $entry->name . ' in Focus.',
            confidence: $confidence,
            providerName: $this->provider()->name(),
            providerModel: $this->provider()->model(),
            pipeline: [['skill' => $entry->name, 'arguments' => []]],
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
        $entry = $manifest->findSkill($skill);
        $result = $this->executor()->execute($skill, $arguments, $manifest, $this->channelFor($entry));

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

    /**
     * Skills the OS chat can route to: the user-facing surfaces (`web` intents and
     * `ui` app-openers), never `console` dev commands (cache:clear, contracts:list,
     * skins:* …). Scoping here both keeps routing on-surface and trims the planner
     * system prompt — a large win on the slow CPU model, where every prompt token
     * is prefill time.
     */
    private const OS_CHANNELS = ['web', 'ui'];

    private function manifest(): SkillManifest
    {
        return (new SkillRegistry())->buildManifest()->forChannels(self::OS_CHANNELS);
    }

    /**
     * The planner LLM request for a user message. Its (large, static) system prompt
     * is what {@see warmPlanner()} primes into the runtime's prefix cache — so both
     * paths MUST build it here, byte-identically, or the warm-up would cache a
     * prefix real turns never reuse.
     */
    private function plannerRequest(string $userMessage, SkillManifest $manifest): LlmRequest
    {
        $persona = (new PersonaRegistry())->framing('os');

        return new LlmRequest(
            systemPrompt: (new Planner())->buildSystemPrompt($manifest, $persona !== '' ? $persona : null),
            userMessage: $userMessage,
            history: [],
        );
    }

    /**
     * The provider used for routing: reasoning trace OFF (we only consume the
     * structured decision, and a thinking-capable model like gemma4 would otherwise
     * spend its token budget on a trace, slowing routing and risking truncated JSON),
     * a capped generation, and a generous timeout — the FIRST turn pays the full
     * manifest prefill while later turns reuse the cached prefix and return fast.
     */
    private function plannerProvider(): LlmProviderInterface
    {
        $provider = $this->provider();
        if ($provider instanceof RemoteOllamaProvider) {
            return $provider->withLimits(160, 0, maxTokens: 320, thinking: false);
        }

        return $provider;
    }

    /**
     * Prime the LLM runtime's prompt-prefix cache with the planner system prompt
     * real turns use, so the first user turn after a worker boots doesn't pay the
     * full cold manifest prefill (~130s on the CPU model vs ~20s warm). Best-effort:
     * fired off the boot path (see the WorkerStart warm-up listener) and swallows
     * every failure — the model may be slow or down, which must not disturb the OS.
     */
    public function warmPlanner(): void
    {
        try {
            $this->plannerProvider()->complete($this->plannerRequest('warm up', $this->manifest()));
        } catch (\Throwable) {
            // Warming is opportunistic — a cold or unreachable model just means the
            // first real turn pays the prefill, exactly as it would without this.
        }
    }

    /**
     * The channel to execute a routed skill on. SkillExecutor gates execution by the
     * skill's declared channels, so we must pass one the skill actually supports —
     * `web` for OS intents, `ui` for app-openers — NOT a channel derived from whether
     * it happens to be a command (os:design-skin is a `web` command). Command vs
     * invocable execution is chosen inside the executor from the skill's skillClass.
     */
    private function channelFor(?SkillEntry $entry): string
    {
        foreach (self::OS_CHANNELS as $channel) {
            if ($entry !== null && in_array($channel, $entry->channels, true)) {
                return $channel;
            }
        }

        return 'web';
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
