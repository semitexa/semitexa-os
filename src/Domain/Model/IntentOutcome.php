<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Model;

use Semitexa\Os\Domain\Enum\IntentDecision;
use Semitexa\Os\Domain\Enum\Surface;

/**
 * The result of running one user intent through the OS Skill loop
 * (Intent -> Plan -> Execute -> Observe).
 *
 * A flat, readonly value object shaped for the Observe surface: every field the
 * web shell needs to render a decision and, when a skill ran, its outcome.
 *
 * @phpstan-type SkillArguments array<string, scalar|null>
 */
final readonly class IntentOutcome
{
    /**
     * @param SkillArguments $arguments arguments the planner proposed for the skill
     */
    public function __construct(
        public string $intent,
        public IntentDecision $decision,
        public ?string $message = null,
        public ?string $reason = null,
        public ?string $skill = null,
        public array $arguments = [],
        public ?string $riskLevel = null,
        public ?float $confidence = null,
        public ?int $exitCode = null,
        public ?string $output = null,
        public ?string $error = null,
        public ?string $providerName = null,
        public ?string $providerModel = null,
        /**
         * The proposed/executed skill chain for orchestration outcomes.
         *
         * @var list<array{skill: string, arguments: array<string, scalar|null>}>
         */
        public array $pipeline = [],
    ) {}

    public function wasExecuted(): bool
    {
        return $this->decision === IntentDecision::Executed;
    }

    /**
     * The human-facing text of this turn, for the dialog transcript: a skill's
     * output when it ran, the error when it failed, otherwise the assistant's
     * message (or the reason behind a gate).
     */
    public function displayText(): string
    {
        if ($this->decision === IntentDecision::Executed) {
            $output = trim((string) $this->output);

            return $output !== '' ? $output : ('Ran ' . ($this->skill ?? 'a skill') . '.');
        }

        if ($this->decision === IntentDecision::Error) {
            return (string) ($this->error ?? 'Something went wrong.');
        }

        return trim((string) ($this->message ?? $this->reason ?? ''));
    }

    public function isSuccess(): bool
    {
        return $this->decision === IntentDecision::Executed && $this->exitCode === 0;
    }

    /**
     * The surface arbiter (concept §8.4): pick the *minimal-sufficient* Observe
     * surface for this outcome — the smallest renderer that satisfies the intent.
     *
     * Gates (ask / refuse / confirm / error) are not Observe surfaces; they
     * resolve to {@see Surface::None} and the shell renders them as gates.
     */
    public function surface(): Surface
    {
        if ($this->decision === IntentDecision::Answer) {
            $message = trim((string) $this->message);
            if ($message === '') {
                return Surface::None;
            }

            return (mb_strlen($message) <= 140 && !str_contains($message, "\n"))
                ? Surface::Cue
                : Surface::Inline;
        }

        if ($this->decision === IntentDecision::Executed) {
            $output = trim((string) $this->output);
            if ($output === '') {
                return Surface::Cue; // it ran; a glance is enough
            }

            $singleShortLine = !str_contains($output, "\n") && mb_strlen($output) <= 80;

            return $singleShortLine ? Surface::Inline : Surface::Window;
        }

        return Surface::None;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'decision' => $this->decision->value,
            'surface' => $this->surface()->value,
            'message' => $this->message,
            'reason' => $this->reason,
            'skill' => $this->skill,
            'arguments' => $this->arguments,
            'risk_level' => $this->riskLevel,
            'confidence' => $this->confidence,
            'exit_code' => $this->exitCode,
            'output' => $this->output,
            'error' => $this->error,
            'provider' => [
                'name' => $this->providerName,
                'model' => $this->providerModel,
            ],
            'pipeline' => $this->pipeline,
        ];
    }
}
