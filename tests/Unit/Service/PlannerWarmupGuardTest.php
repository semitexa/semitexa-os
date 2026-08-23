<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Os\Application\Service\Server\PlannerWarmupListener;
use Semitexa\Os\Application\Service\SkillLoopRunner;

/**
 * The two checks that keep an optional warm-up from becoming a crash loop.
 *
 * The warm-up primes the model's prefix cache at worker start. It is pure optimisation
 * — and it used to be reached by the full completion path, with no reachability check
 * in front of it, from a listener that re-arms in every worker Swoole spawns. Against
 * an LLM host that had gone away that combination respawned worker 0 1703 times over
 * two days at ~60-75% of a core, with nothing but a per-crash line that read like an
 * ordinary one-off crash.
 *
 * Both guards are structural on purpose. The behavioural path needs a live Swoole
 * server, a container and a reachable model, and a test that heavy would be skipped in
 * exactly the environments where the regression bites. What must not silently come back
 * is the ORDER of two calls and the presence of one early return, and those the source
 * states plainly.
 */
final class PlannerWarmupGuardTest extends TestCase
{
    #[Test]
    public function the_warm_up_checks_the_model_is_reachable_before_paying_for_a_completion(): void
    {
        $body = self::methodSource(SkillLoopRunner::class, 'warmPlanner');

        $health = strpos($body, 'healthCheck()');
        $complete = strpos($body, 'completePlanner(');

        self::assertNotFalse(
            $health,
            'warmPlanner() no longer probes healthCheck(); an unreachable endpoint is back to '
                . 'costing a full connect timeout per retry on the worker boot path',
        );
        self::assertNotFalse($complete, 'warmPlanner() no longer completes anything');
        self::assertLessThan(
            $complete,
            $health,
            'the reachability probe must come BEFORE the completion — swallowing the failure '
                . 'afterwards does not refund the time already spent waiting for it',
        );
    }

    #[Test]
    public function the_warm_up_stands_down_in_a_worker_that_keeps_crashing(): void
    {
        $body = self::methodSource(PlannerWarmupListener::class, 'handle');

        self::assertStringContainsString(
            'workerIsCrashLooping()',
            $body,
            'the listener no longer asks whether this worker has been dying at boot; its own '
                . '$armed flag is per-process and says nothing about the workers it replaced',
        );
    }

    #[Test]
    public function the_warm_up_still_belongs_to_one_worker_only(): void
    {
        // Not part of the crash-loop fix, but the fix is written on top of it: the guards
        // above only bound worker 0's boot, so a warm-up that spread to every worker would
        // quietly widen the blast radius again.
        $body = self::methodSource(PlannerWarmupListener::class, 'handle');

        self::assertStringContainsString('workerId !== 0', $body);
    }

    private static function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);

        $lines = file($file);
        self::assertIsArray($lines);

        $start = $reflection->getStartLine() - 1;
        $length = $reflection->getEndLine() - $start;

        return implode('', array_slice($lines, $start, $length));
    }
}
