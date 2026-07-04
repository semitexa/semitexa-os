<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service\Server;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Os\Application\Service\Weaver;
use Semitexa\Core\Environment;
use Swoole\Timer;

/**
 * The weaver's heartbeat — a per-worker {@see Timer::tick} that drives
 * {@see Weaver::tick()} so the knowledge graph grows from conversation in the
 * background, with no scheduler container (works identically in web mode and
 * on the OS machine). Modelled on {@see \Semitexa\Tasks\Application\Service\Server\TaskTickTimerListener}.
 *
 * A tick is cheap when there is nothing to weave (one cursor read + one indexed
 * query); the Weaver's own idle gate keeps LLM calls away from a live exchange.
 * Guarded to worker 0: the pass MUTATES the graph and advances a shared cursor,
 * so exactly one worker may run it.
 *
 * Kill switch: `OS_WEAVER=off` disables the timer (the `os:weave` command still
 * works) — useful when the single-slot LLM must be left alone.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartFinalize->value,
    priority: 0,
    requiresContainer: true,
)]
final class WeaveTimerListener implements ServerLifecycleListenerInterface
{
    /** Tick cadence — batched extraction; the idle gate makes most ticks a no-op. */
    private const TICK_INTERVAL_MS = 60000;

    /** Per-worker timer id so a phase re-run does not arm a second timer. */
    private static int $timerId = 0;

    #[InjectAsReadonly]
    protected Weaver $weaver;

    public function handle(ServerLifecycleContext $context): void
    {
        if (!class_exists(Timer::class, false)) {
            return; // not under Swoole (e.g. CLI) — os:weave covers that
        }
        if ($context->workerId !== 0) {
            return; // single owner — see class docblock
        }
        if (self::$timerId !== 0) {
            return; // already armed in this worker
        }
        if (strtolower((string) Environment::getEnvValue('OS_WEAVER')) === 'off') {
            return;
        }

        $weaver = $this->weaver;
        self::$timerId = Timer::tick(
            self::TICK_INTERVAL_MS,
            static function () use ($weaver): void {
                $weaver->tick();
            },
        );
    }

    /** Test/worker-stop hygiene: clear the per-worker timer. */
    public static function reset(): void
    {
        if (self::$timerId !== 0 && class_exists(Timer::class, false)) {
            Timer::clear(self::$timerId);
        }
        self::$timerId = 0;
    }
}
