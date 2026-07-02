<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service\Server;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Os\Application\Service\SkillLoopRunner;
use Swoole\Timer;

/**
 * Warms the LLM prompt-prefix cache at worker start so the first chat intent
 * after a boot doesn't pay the full cold manifest prefill (~130s on the CPU
 * model vs ~20s once the prefix is cached). Fires exactly one planner completion
 * — {@see SkillLoopRunner::warmPlanner()} — whose system prompt is byte-identical
 * to real turns, so the runtime (Ollama) caches the KV of that prefix.
 *
 * Modelled on {@see \Semitexa\Tasks\Application\Service\Server\TaskTickTimerListener}:
 * armed at {@see ServerLifecyclePhase::WorkerStartFinalize} (the worker has its
 * event loop), with a static per-worker guard so a phase re-run never fires twice.
 *
 * Guarded to worker 0: the warm call primes a cache on the SHARED remote model,
 * so one worker priming it benefits every worker's requests — and firing from all
 * N workers at once would just congest the single-slot model at boot. Deferred a
 * beat via {@see Timer::after} so the (slow, cold) warm call runs off the boot
 * path and never delays the worker becoming ready.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartFinalize->value,
    priority: 0,
    requiresContainer: true,
)]
final class PlannerWarmupListener implements ServerLifecycleListenerInterface
{
    /** Small delay so the warm-up runs after the worker is ready, not during boot. */
    private const WARMUP_DELAY_MS = 1500;

    /** Per-worker guard so a phase re-run does not fire a second warm-up. */
    private static bool $armed = false;

    #[InjectAsReadonly]
    protected SkillLoopRunner $loop;

    public function handle(ServerLifecycleContext $context): void
    {
        if (!class_exists(Timer::class, false)) {
            return; // not under Swoole (e.g. CLI)
        }
        if ($context->workerId !== 0) {
            return; // single owner primes the shared model cache — see class docblock
        }
        if (self::$armed) {
            return; // already warmed in this worker
        }
        self::$armed = true;

        $loop = $this->loop;
        Timer::after(self::WARMUP_DELAY_MS, static function () use ($loop): void {
            $loop->warmPlanner();
        });
    }

    /** Test/worker-stop hygiene: allow re-arming in a fresh worker. */
    public static function reset(): void
    {
        self::$armed = false;
    }
}
