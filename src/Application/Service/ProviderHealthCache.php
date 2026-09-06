<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;

/**
 * Remembers whether the model provider answered, for a few seconds.
 *
 * The OS shell shows provider health as a status dot, and it asked the provider
 * directly on every boot. MEASURED 2026-09-06 against the live Gemini endpoint:
 * 280.3 / 231.7 / 220.4 ms per call on warm runs, while the same request spent
 * 2.76 ms in the database and 6.71 ms rendering. So opening the OS meant waiting
 * on Google for roughly the entire request, and `CURLOPT_TIMEOUT = 5` bounded the
 * bad day at five seconds of an empty screen.
 *
 * A dot does not need a fresh round trip per boot. It needs an answer that was
 * true recently, which is what this gives: one call per worker per TTL, shared
 * across the requests that arrive in between.
 *
 * NOT a general-purpose health facade. Callers that are about to spend real
 * money or time on the provider — the skill loop, the weaver, the skin
 * commands — keep asking it directly, because for them a stale "yes" costs a
 * failed run rather than a wrong pixel.
 *
 * Worker-lifetime state on purpose. This is not request-scoped data leaking
 * across coroutines (the trap CoroutineLocal exists for): the cached fact is
 * about the provider, identical for every request the worker serves. Two
 * coroutines can refresh at once; the loser overwrites with the same answer.
 */
#[AsService]
final class ProviderHealthCache
{
    /**
     * Short enough that a provider going down is noticed within a screen
     * refresh or two, long enough that a burst of boots costs one call.
     */
    private const TTL_SECONDS = 30.0;

    /** @var array<string, array{0: bool, 1: float}> keyed by provider and model */
    private array $answers = [];

    public function isHealthy(LlmProviderInterface $provider, ?float $now = null): bool
    {
        $now ??= microtime(true);
        $key = $provider->name() . "\0" . $provider->model();

        $cached = $this->answers[$key] ?? null;
        if ($cached !== null && ($now - $cached[1]) < self::TTL_SECONDS) {
            return $cached[0];
        }

        try {
            $healthy = $provider->healthCheck();
        } catch (\Throwable) {
            // An exception is an answer: the provider did not respond. Cached
            // like any other, so a provider that throws on every call does not
            // cost a round trip per request.
            $healthy = false;
        }

        $this->answers[$key] = [$healthy, $now];

        return $healthy;
    }
}
