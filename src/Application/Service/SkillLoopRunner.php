<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Application as ConsoleApplication;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Llm\Application\Service\ConversationSummarizer;
use Semitexa\Llm\Application\Service\GeminiProvider;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Application\Service\PersonaRegistry;
use Semitexa\Llm\Application\Service\RemoteOllamaProvider;
use Semitexa\Llm\Application\Service\Planner;
use Semitexa\Llm\Application\Service\PlannerToolSchema;
use Semitexa\Llm\Application\Service\SkillExecutor;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\PlannerResponseType;
use Semitexa\Llm\Domain\Model\ExecutionResult;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;
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
 * `console` channel. The planner now also receives a bounded window of recent
 * transcript turns as conversation history ({@see plannerHistory()}), so it keeps
 * focus across turns instead of treating each message as first contact
 * (`ep-llm-orchestrator-v1 / tk-llm-mem-wire`); rolling-summary + a real
 * observe-act loop are the remaining tasks under that epic.
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

    /** Rolling summary of everything older than the verbatim window. */
    #[InjectAsReadonly]
    protected ConversationSummaryStore $summaries;

    /** Supplies the user's wall-clock timezone for the planner's date anchor. */
    #[InjectAsReadonly]
    protected OsPreferences $prefs;

    /**
     * The worker container, used to resolve invocable skills WITH property
     * injection so tenant-aware stores reach them (see executor()). Injected
     * rather than reached via ContainerFactory:: (the staticContainerAccess
     * rule) — the container is itself an injectable readonly service.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

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
        // The returned id (not just the text) is how plannerHistory() recognises
        // THIS turn later — text-equality alone can't tell it apart from another
        // same-tenant request that persisted its own turn in between.
        $currentTurnId = $this->conversation->append(ConversationStore::ROLE_USER, $intent);

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

        $outcome = $this->orchestrate($intent, $manifest, $currentTurnId);

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

    /**
     * Bounded observe→act orchestration — the successor to the single-shot router.
     *
     * Instead of "plan once, execute once", the planner is LOOPED: after an
     * auto-executable skill runs, its output is fed back as an observation and the
     * planner decides the next step — run another skill, answer the user FROM the
     * results, ask, or stop — until it reaches a terminal decision or the step
     * budget is spent. This is what turns "guess one skill" into orchestration:
     * the model can chain skills adaptively and phrase a final reply from what they
     * returned, rather than dumping a single skill's raw output.
     *
     * Terminal at any step (returned immediately, never continued): answer / ask /
     * refuse, a UI-skill (opens a dialog and hands off to Focus), a confirmation-
     * gated skill (needs the human — resumed later via {@see approveAndExecute()}),
     * or a proposed pipeline. Only an auto-executable (`confirmation: never`)
     * non-UI skill advances the loop.
     *
     * Depth is gated by backend ({@see maxAgentSteps()}): a capable model gets a
     * real chain; the weak local model stays effectively single-shot — degraded,
     * but never worse than the pre-loop behavior.
     */
    private function orchestrate(string $intent, SkillManifest $manifest, string $currentTurnId): IntentOutcome
    {
        $maxSteps = $this->maxAgentSteps();

        $working = $this->plannerHistory($intent, $currentTurnId); // summary + verbatim window
        $userMessage = $intent;

        /** @var list<array{skill: string, arguments: array<string, mixed>, result: ExecutionResult}> $steps */
        $steps = [];
        /** @var list<array{skill: string, arguments: array<string, mixed>}> $executedCalls */
        $executedCalls = [];
        $observations = [];
        $lastConfidence = null;

        for ($step = 1; $step <= $maxSteps; $step++) {
            $response = $this->plan($userMessage, $manifest, $working);
            $lastConfidence = $response->confidence ?? $lastConfidence;

            // Terminal replies — return the assistant's decision as the final outcome,
            // carrying the skills already run this turn so the shell shows the trail.
            if ($response->type === PlannerResponseType::Answer) {
                return $this->observe($intent, IntentDecision::Answer, $response, $this->pipelineOf($steps));
            }
            if ($response->type === PlannerResponseType::Ask) {
                return $this->observe($intent, IntentDecision::Ask, $response, $this->pipelineOf($steps));
            }
            if ($response->type === PlannerResponseType::Refuse) {
                return $this->observe($intent, IntentDecision::Refuse, $response, $this->pipelineOf($steps));
            }
            if ($response->type === PlannerResponseType::ProposePipeline) {
                return $this->handlePipeline($intent, $response, $manifest);
            }

            // ProposeSkill.
            $skill = $response->skill;
            if ($skill === null) {
                return $this->finalize($intent, $steps, $lastConfidence, 'Planner proposed a skill but did not name one.');
            }
            $entry = $manifest->findSkill($skill);
            if ($entry === null) {
                return new IntentOutcome(
                    intent: $intent,
                    decision: IntentDecision::Error,
                    skill: $skill,
                    arguments: $response->arguments,
                    reason: $response->reason,
                    pipeline: $this->pipelineOf($steps),
                    error: "Planner proposed unknown skill '{$skill}' — rejected.",
                );
            }
            // Canonicalize: findSkill() tolerates model drift ('attach_folder' for
            // 'attach-folder'), but everything downstream must see ONE canonical name.
            $skill = $entry->name;

            // UI + confirmation skills hand off to the user, so they can't be
            // auto-continued — return them as terminal outcomes (single-shot parity).
            if ($entry->isUi()) {
                return $this->handleUiSkill($intent, $entry, $response->reason, $response->confidence);
            }
            if ($entry->confirmation !== AiConfirmationMode::Never) {
                return new IntentOutcome(
                    intent: $intent,
                    decision: IntentDecision::NeedsConfirmation,
                    reason: $response->reason,
                    skill: $skill,
                    arguments: $response->arguments,
                    riskLevel: $entry->riskLevel->value,
                    confidence: $response->confidence,
                    pipeline: $this->pipelineOf($steps),
                    providerName: $this->provider()->name(),
                    providerModel: $this->provider()->model(),
                );
            }

            // Loop guard: an identical re-proposal means the model is spinning —
            // stop and finalize with what we have rather than burning the budget.
            // Strict array comparison, NOT a JSON-encoded string key: json_encode
            // on an associative array is insertion-order-sensitive, and nothing
            // guarantees the model emits argument keys in the same order twice.
            $call = ['skill' => $skill, 'arguments' => $response->arguments];
            if (in_array($call, $executedCalls, true)) {
                break;
            }
            $executedCalls[] = $call;

            $result = $this->executor()->execute($skill, $response->arguments, $manifest, $this->channelFor($entry));
            $steps[] = ['skill' => $skill, 'arguments' => $response->arguments, 'result' => $result];

            // Fold results into a fresh USER message every step — $working (the
            // plannerHistory() context) is NEVER mutated mid-loop. Two reasons:
            // (1) a synthetic assistant/model turn between calls corrupts native
            // function-calling (Gemini answers with an empty STOP); (2) appending
            // the intent into $working once and then letting the provider append
            // userMessage after it produces TWO consecutive user-role turns for
            // every step from here on — not a conformant multi-turn shape.
            // Repeating the intent alongside the running results keeps exactly
            // one user-role message per call and never loses the original ask.
            $observations[] = $this->observationLine($skill, $result);
            $userMessage = $intent . "\n\nResults so far:\n" . implode("\n\n", $observations) . "\n\n" . self::CONTINUE_NUDGE;
        }

        return $this->finalize($intent, $steps, $lastConfidence);
    }

    /**
     * Max planner round-trips per intent, gated by backend capability. A capable
     * model orchestrates a real chain; the slow local model stays single-shot
     * (`1` == the exact pre-loop behavior), so the weak backend is never made worse.
     */
    private function maxAgentSteps(): int
    {
        $provider = $this->provider();
        if ($provider instanceof GeminiProvider) {
            return 5;
        }
        if ($provider instanceof RemoteOllamaProvider) {
            return 2;
        }

        return 1;
    }

    /** Cap on how much of a skill's output is fed back as an observation. */
    private const OBSERVATION_MAX_CHARS = 1500;

    private const CONTINUE_NUDGE = "Continue fulfilling the user's original request using this result."
        . " If the request is now fully satisfied, respond with an \"answer\" that gives the user the result directly."
        . ' Otherwise propose the next skill.';

    /**
     * One skill's contribution to the running progress note fed back to the
     * planner: the skill's (bounded) output, or its error. The continue-or-answer
     * nudge is appended once by the caller around the joined lines.
     */
    private function observationLine(string $skill, ExecutionResult $result): string
    {
        if ($result->exitCode !== 0) {
            $error = trim((string) $result->error);

            return "The skill `{$skill}` failed with: " . ($error !== '' ? $error : 'unknown error');
        }

        $output = trim($result->output);
        if (mb_strlen($output) > self::OBSERVATION_MAX_CHARS) {
            $output = mb_substr($output, 0, self::OBSERVATION_MAX_CHARS) . "\n…(truncated)";
        }
        if ($output === '') {
            $output = '(the skill produced no output)';
        }

        return "Result of skill `{$skill}`:\n{$output}";
    }

    /**
     * The executed skill chain as the {@see IntentOutcome::$pipeline} trail.
     *
     * @param list<array{skill: string, arguments: array<string, mixed>, result: ExecutionResult}> $steps
     * @return list<array{skill: string, arguments: array<string, mixed>}>
     */
    private function pipelineOf(array $steps): array
    {
        return array_map(
            static fn (array $step): array => ['skill' => $step['skill'], 'arguments' => $step['arguments']],
            $steps,
        );
    }

    /**
     * Build the final outcome when the loop ends by exhausting its step budget (or
     * a spinning guard) rather than on a terminal answer: report what actually ran.
     * A single skill reads exactly like the old single-shot Executed outcome; a
     * chain combines every step's output and lists the trail in `pipeline`.
     *
     * @param list<array{skill: string, arguments: array<string, mixed>, result: ExecutionResult}> $steps
     */
    private function finalize(string $intent, array $steps, ?float $confidence, ?string $error = null): IntentOutcome
    {
        // A malformed planner reply (e.g. propose_skill with no name) must surface
        // as an error EVEN IF earlier steps in this same chain already executed
        // successfully — falling through to report the last good step as the
        // whole turn's result would silently hide that the planner malfunctioned
        // partway through. `pipeline` still carries whatever DID run, so the
        // caller sees the partial progress alongside the error.
        if ($error !== null) {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::Error,
                confidence: $confidence,
                error: $error,
                pipeline: $this->pipelineOf($steps),
                providerName: $this->provider()->name(),
                providerModel: $this->provider()->model(),
            );
        }

        if ($steps === []) {
            return new IntentOutcome(
                intent: $intent,
                decision: IntentDecision::Error,
                confidence: $confidence,
                error: 'The assistant could not act on this request.',
            );
        }

        $last = $steps[count($steps) - 1]['result'];
        $single = count($steps) === 1;
        $combined = implode("\n\n", array_map(
            static fn (array $step): string => '» ' . $step['skill'] . "\n" . trim($step['result']->output),
            $steps,
        ));

        return new IntentOutcome(
            intent: $intent,
            decision: IntentDecision::Executed,
            skill: $single ? $steps[0]['skill'] : null,
            arguments: $single ? $steps[0]['arguments'] : [],
            confidence: $confidence,
            exitCode: $last->exitCode,
            output: $single ? $last->output : $combined,
            error: $last->error,
            providerName: $this->provider()->name(),
            providerModel: $this->provider()->model(),
            pipeline: $this->pipelineOf($steps),
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

    /**
     * @param list<array{skill: string, arguments: array<string, mixed>}> $pipeline
     *        the skills already run this turn before the assistant settled on a
     *        non-executing reply (empty for a plain single-shot answer/ask/refuse)
     */
    private function observe(
        string $intent,
        IntentDecision $decision,
        PlannerResponse $response,
        array $pipeline = [],
    ): IntentOutcome {
        return new IntentOutcome(
            intent: $intent,
            decision: $decision,
            message: $response->message ?? $response->reason,
            reason: $response->reason,
            confidence: $response->confidence,
            providerName: $this->provider()->name(),
            providerModel: $this->provider()->model(),
            pipeline: $pipeline,
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
    /**
     * Verbatim window: the minimum number of most-recent turns always replayed to
     * the planner word-for-word. Small on purpose — the model must see the recent
     * thread (to fuse fragmented intents and stop treating each turn as first
     * contact) WITHOUT the unbounded transcript blowing up prefill on the slow CPU
     * model. Everything older is compressed into the rolling summary.
     */
    private const LIVE_WINDOW = 8;

    /**
     * Fold hysteresis: only compress once at least this many turns have piled up
     * BEYOND the window. Folding on every turn would fire a summary LLM call each
     * turn; batching keeps the verbatim tail between {@see LIVE_WINDOW} and
     * `LIVE_WINDOW + FOLD_BATCH` while summarizing only once per batch.
     */
    private const FOLD_BATCH = 6;

    /**
     * Cap on fold rounds per {@see plannerHistory()} call. `turnsAfter()` is
     * oldest-first, so a backlog bigger than one fetch (a tenant using this
     * feature for the first time with existing history, or several turns of
     * failed folds) would otherwise keep re-surfacing ancient turns as if they
     * were "recent" — one round only drains {@see FOLD_BATCH} turns per real user
     * turn. Looping catches up several batches within a single request instead;
     * bounded so a very large backlog can't turn one request into an unbounded
     * chain of blocking summarizer calls.
     */
    private const MAX_FOLD_ROUNDS = 4;

    /**
     * Assemble the planner's conversation context: the rolling summary (as a
     * single leading context message) followed by the verbatim window of recent
     * turns. Folds the oldest overflow into the summary when the uncovered tail
     * grows past the window, so a long dialogue stays cheap to replay without ever
     * leaving a gap between what the summary covers and what the window shows.
     *
     * {@see run()} persists the user's turn BEFORE planning (crash-safety), so the
     * transcript's last row is already the current intent — and that same text is
     * passed separately as {@see LlmRequest::$userMessage}. Replaying it here too
     * would double it, so the just-appended current turn is dropped from the tail.
     *
     * Assistant turns carry the human-facing reply text (what the user actually
     * saw), not the internal planner JSON — that is the dialogue as it happened.
     *
     * @return list<array{role: string, content: string}>
     */
    private function plannerHistory(string $currentIntent, string $currentTurnId): array
    {
        $summary = $this->summaries->get();
        $cap = self::LIVE_WINDOW + self::FOLD_BATCH + 4;

        $uncovered = $this->conversation->turnsAfter($summary['covered_through_id'], $cap);
        $uncovered = $this->dropCurrentTurn($uncovered, $currentIntent, $currentTurnId);

        for ($round = 0; $round < self::MAX_FOLD_ROUNDS; $round++) {
            $overflow = count($uncovered) - self::LIVE_WINDOW;
            if ($overflow < self::FOLD_BATCH) {
                break;
            }

            $batch = array_slice($uncovered, 0, $overflow);
            $folded = $this->foldIntoSummary($summary, $batch);
            if (!$folded['changed']) {
                // Summarization failed (transient provider error) — do NOT
                // advance the cursor. The batch stays uncovered and is retried
                // on the next call instead of silently vanishing from context.
                break;
            }

            $summary = $folded;
            // Re-fetch from the new cursor rather than just slicing the old
            // fetch: a backlog bigger than $cap has more turns waiting beyond
            // what the first fetch could see.
            $uncovered = $this->conversation->turnsAfter($summary['covered_through_id'], $cap);
            $uncovered = $this->dropCurrentTurn($uncovered, $currentIntent, $currentTurnId);
        }

        $history = [];

        // The summary rides as a single leading context message so the (large,
        // static) system prompt stays byte-identical and prefix-cacheable.
        $context = $this->summaryContextMessage($summary);
        if ($context !== null) {
            $history[] = $context;
        }

        foreach ($uncovered as $turn) {
            $content = trim($turn['text']);
            if ($content === '') {
                continue;
            }
            $history[] = [
                'role' => $turn['role'] === ConversationStore::ROLE_USER
                    ? ConversationStore::ROLE_USER
                    : ConversationStore::ROLE_ASSISTANT,
                'content' => $content,
            ];
        }

        return $history;
    }

    /**
     * Drop the trailing current-intent user row (persisted just before planning)
     * so it isn't replayed twice — once here, once as the separate userMessage.
     * Prefers the exact turn id ({@see run()}'s `append()` return) over a
     * text-equality heuristic: two concurrent same-tenant requests (double-submit,
     * two open tabs) can each persist their own turn before either plans, and a
     * text match can't tell them apart if that happens.
     *
     * @param list<array{id: string, at: string, role: string, text: string, meta: array<string, mixed>}> $turns
     * @return list<array{id: string, at: string, role: string, text: string, meta: array<string, mixed>}>
     */
    private function dropCurrentTurn(array $turns, string $currentIntent, string $currentTurnId): array
    {
        $last = $turns[count($turns) - 1] ?? null;
        if ($last === null || $last['role'] !== ConversationStore::ROLE_USER) {
            return $turns;
        }

        $isCurrentTurn = $currentTurnId !== ''
            ? $last['id'] === $currentTurnId
            : trim($last['text']) === trim($currentIntent);

        if ($isCurrentTurn) {
            array_pop($turns);
        }

        return $turns;
    }

    /**
     * Compress a batch of aging turns into the running summary and persist it.
     * Best-effort: the summarizer returns the PRIOR summary unchanged (`changed:
     * false`) on any model/parse failure — the caller MUST check that flag before
     * advancing its cursor, or a transient failure silently drops this batch from
     * everything the planner ever sees again (the durable transcript still has it,
     * but plannerHistory() would never surface it).
     *
     * @param array{summary: string, active_intent: string, covered_through_id: string} $summary
     * @param list<array{id: string, at: string, role: string, text: string, meta: array<string, mixed>}> $batch
     * @return array{summary: string, active_intent: string, covered_through_id: string, changed: bool}
     */
    private function foldIntoSummary(array $summary, array $batch): array
    {
        if ($batch === []) {
            return $summary + ['changed' => false];
        }

        $turns = [];
        foreach ($batch as $turn) {
            $turns[] = [
                'role' => $turn['role'] === ConversationStore::ROLE_USER ? 'user' : 'assistant',
                'content' => $turn['text'],
            ];
        }

        $folded = (new ConversationSummarizer())->summarize(
            $this->summaryProvider(),
            $summary['summary'],
            $summary['active_intent'],
            $turns,
        );

        if (!$folded['changed']) {
            return $summary + ['changed' => false];
        }

        $coveredThroughId = $batch[count($batch) - 1]['id'];
        $this->summaries->save($folded['summary'], $folded['active_intent'], $coveredThroughId);

        return [
            'summary' => $folded['summary'],
            'active_intent' => $folded['active_intent'],
            'covered_through_id' => $coveredThroughId,
            'changed' => true,
        ];
    }

    /**
     * The rolling summary rendered as a single leading `user`-role context message,
     * or null when there is nothing summarized yet. Kept as a plain history turn
     * (not appended to the system prompt) so the static system prefix stays cached.
     *
     * @param array{summary: string, active_intent: string, covered_through_id: string} $summary
     * @return array{role: string, content: string}|null
     */
    private function summaryContextMessage(array $summary): ?array
    {
        $text = trim($summary['summary']);
        $intent = trim($summary['active_intent']);
        if ($text === '' && $intent === '') {
            return null;
        }

        $body = "[Summary of the earlier conversation]\n" . ($text !== '' ? $text : '(nothing notable yet)');
        if ($intent !== '') {
            $body .= "\n\n[The user's current focus]\n" . $intent;
        }

        return ['role' => ConversationStore::ROLE_USER, 'content' => $body];
    }

    /**
     * Provider for the summary fold: token-capped and reasoning-trace off (we only
     * consume the JSON), mirroring {@see plannerProvider()}. Falls back to the base
     * provider for backends without a `withLimits()` seam.
     */
    private function summaryProvider(): LlmProviderInterface
    {
        $provider = $this->provider();
        $maxTokens = (new ConversationSummarizer())->maxTokens();

        if ($provider instanceof RemoteOllamaProvider || $provider instanceof GeminiProvider) {
            return $provider->withLimits(60, 1, maxTokens: $maxTokens, thinking: false);
        }

        return $provider;
    }

    /**
     * @param list<array{role: string, content: string}> $history recent transcript
     *        turns (oldest → newest) replayed so the model keeps conversational
     *        focus. Empty for {@see warmPlanner()} — the warm-up primes the static
     *        system prefix, and history is separate messages that don't touch it.
     */
    private function plannerRequest(string $userMessage, SkillManifest $manifest, array $history = []): LlmRequest
    {
        $persona = $this->plannerPersona();

        return new LlmRequest(
            systemPrompt: (new Planner())->buildSystemPrompt(
                $manifest,
                $persona !== '' ? $persona : null,
                // Anchor "today"/"tomorrow" in the USER's zone — the server runs
                // in UTC, which resolves "завтра" to the wrong day near midnight.
                $this->prefs->timezone(),
            ),
            userMessage: $userMessage,
            history: $history,
        );
    }

    /**
     * The native function-calling counterpart of {@see plannerRequest()}: a lean
     * tool-calling system prompt (no skills list, no JSON block) plus the manifest
     * exposed as tool declarations. Used for tool-calling backends (Gemini).
     *
     * @param list<array{role: string, content: string}> $history
     */
    private function plannerToolRequest(string $userMessage, SkillManifest $manifest, array $history): LlmRequest
    {
        $persona = $this->plannerPersona();

        return new LlmRequest(
            systemPrompt: (new Planner())->buildToolSystemPrompt(
                $persona !== '' ? $persona : null,
                $this->prefs->timezone(),
            ),
            userMessage: $userMessage,
            history: $history,
            tools: (new PlannerToolSchema())->declarationsFor($manifest),
        );
    }

    /**
     * The persona framing shared by both planner request shapes: the OS identity
     * plus the reply-language pin. Without an explicit language instruction the
     * model guesses from the user's phrasing and drifts (answering English to a
     * Ukrainian OS); the OS locale wins, '' = the request's resolved locale.
     */
    private function plannerPersona(): string
    {
        $persona = (new PersonaRegistry())->framing('os');
        $locale = $this->prefs->locale();
        if ($locale === '') {
            $locale = \Semitexa\Locale\Context\LocaleContextStore::getLocale();
        }
        $language = self::LANGUAGE_NAMES[$locale] ?? null;
        if ($language !== null && $persona !== '') {
            $persona .= "\nAlways reply in {$language}, regardless of the language the user writes in — unless they explicitly ask you to switch (then use the set-locale skill).";
        }

        return $persona;
    }

    /**
     * One planner round-trip → a {@see PlannerResponse}. A tool-calling backend
     * (Gemini) gets the manifest as native tools and returns a structured call we
     * map directly ({@see PlannerToolSchema}); if it answers with text instead, we
     * fall back to the JSON/text parse. Every other backend uses the JSON contract
     * — the degraded but proven path.
     *
     * @param list<array{role: string, content: string}> $working
     */
    private function plan(string $userMessage, SkillManifest $manifest, array $working): PlannerResponse
    {
        if ($this->provider() instanceof GeminiProvider) {
            $response = $this->completePlanner($this->plannerToolRequest($userMessage, $manifest, $working));
            if ($response->toolCall !== null) {
                return (new PlannerToolSchema())->mapToolCall($response->toolCall, $manifest);
            }

            // No tool call — the model replied with text. Parse it as it stands
            // (a plain answer, or the legacy JSON contract if it emitted one).
            return (new Planner())->parseResponse($response, manifest: $manifest);
        }

        return (new Planner())->parseResponse(
            $this->completePlanner($this->plannerRequest($userMessage, $manifest, $working)),
            manifest: $manifest, // lets the parser salvage `{"type":"<skill-name>"}` model drift
        );
    }

    /**
     * True once ANY planner completion in this worker has succeeded — the shared
     * runtime's prompt-prefix cache is warm from here on (warmup or a real turn,
     * whichever lands first).
     */
    private bool $plannerWarm = false;

    /** Locale code → language name for the reply-language instruction. */
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'uk' => 'Ukrainian (українська)',
        'de' => 'German (Deutsch)',
        'pl' => 'Polish (polski)',
        'ru' => 'Russian',
    ];

    /**
     * The provider used for routing: reasoning trace OFF (we only consume the
     * structured decision, and a thinking-capable model like gemma4 would otherwise
     * spend its token budget on a trace, slowing routing and risking truncated JSON),
     * a capped generation, and warm-aware limits.
     *
     * Cold (no completed planner call in this worker yet): the call may PAY the
     * full manifest prefill (~130s on the CPU model) AND queue behind the boot
     * warm-up on the single-slot runtime — 160s used to time out right there and
     * refuse the user's first message. So the first call gets a 300s budget.
     * Warm: the cached prefix returns in ~20s; 160s is generous. Either way one
     * retry — a timed-out attempt still advanced the runtime's prefix cache, so
     * the retry typically completes fast instead of the user seeing a refusal.
     */
    private function plannerProvider(): LlmProviderInterface
    {
        $provider = $this->provider();
        if ($provider instanceof RemoteOllamaProvider) {
            return $provider->withLimits($this->plannerWarm ? 160 : 300, 1, maxTokens: 320, thinking: false);
        }
        // Gemini planner/tool calls: reasoning trace OFF. We only consume the
        // structured tool call (or a short reply), and with thinking ON a compound
        // request under native tools routinely comes back as an EMPTY candidate
        // (finishReason STOP, no parts) — the model spends the turn thinking and
        // emits nothing callable. Disabling it makes tool calls deterministic and
        // fast. Tokens stay uncapped so a `final_answer` reply is never truncated.
        if ($provider instanceof GeminiProvider) {
            return $provider->withLimits(60, 1, maxTokens: null, thinking: false);
        }

        return $provider;
    }

    /**
     * All planner completions go through here so warm-state tracking can't be
     * forgotten at a call site.
     */
    private function completePlanner(LlmRequest $request): LlmResponse
    {
        $response = $this->plannerProvider()->complete($request);
        if ($response->success) {
            $this->plannerWarm = true;
        }

        return $response;
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
            $this->completePlanner($this->plannerRequest('warm up', $this->manifest()));
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
            // DI-backed skill resolution: without a resolver SkillExecutor
            // does `new $class()`, so a skill that touches tenant data (weave
            // recall/show/remember) would construct its store bare — with NO
            // injected coroutine-local tenant context — and silently read/write
            // the 'default' partition regardless of the request's tenant. The
            // resolver constructs the skill through the container and applies
            // #[InjectAsReadonly], so injected stores carry the request tenant.
            $container = $this->container;
            $this->executor = new SkillExecutor(
                $this->consoleApp,
                static function (string $class) use ($container): object {
                    // resolve() auto-wires the skill; injectInto() applies its
                    // #[InjectAsReadonly] (the tenant-aware stores). Both live on
                    // the concrete container; the bound type is the PSR interface.
                    if ($container instanceof SemitexaContainer) {
                        $skill = $container->resolve($class);
                        $container->injectInto($skill);

                        return $skill;
                    }

                    return new $class();
                },
            );
        }

        return $this->executor;
    }
}
