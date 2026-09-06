<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Os\Application\Service\ProviderHealthCache;

/**
 * The shell's status dot must not cost a network round trip per boot.
 *
 * MEASURED before this existed: healthCheck() took 280.3 / 231.7 / 220.4 ms
 * against the live provider, against 2.76 ms of database and 6.71 ms of
 * rendering in the same request. The point of the cache is the CALL COUNT, so
 * that is what these assert — a timing assertion would be a flake.
 */
final class ProviderHealthCacheTest extends TestCase
{
    #[Test]
    public function a_burst_of_boots_costs_one_call(): void
    {
        $provider = $this->provider(true);
        $cache = new ProviderHealthCache();

        for ($i = 0; $i < 10; $i++) {
            self::assertTrue($cache->isHealthy($provider, 1_000.0 + $i));
        }

        self::assertSame(1, $provider->calls, 'ten boots inside the window must ask the provider once');
    }

    #[Test]
    public function the_answer_is_refreshed_once_the_window_passes(): void
    {
        $provider = $this->provider(true);
        $cache = new ProviderHealthCache();

        $cache->isHealthy($provider, 1_000.0);
        $cache->isHealthy($provider, 1_029.9);
        self::assertSame(1, $provider->calls, 'still inside the window');

        $cache->isHealthy($provider, 1_030.1);
        self::assertSame(2, $provider->calls, 'past the window the provider is asked again');
    }

    /** A provider that goes down must be noticed, not remembered as healthy forever. */
    #[Test]
    public function a_provider_that_falls_over_is_reported_after_the_window(): void
    {
        $provider = $this->provider(true);
        $cache = new ProviderHealthCache();

        self::assertTrue($cache->isHealthy($provider, 1_000.0));

        $provider->healthy = false;
        self::assertTrue($cache->isHealthy($provider, 1_010.0), 'inside the window the old answer stands');
        self::assertFalse($cache->isHealthy($provider, 1_040.0));
    }

    /** Two providers are two facts; one must not answer for the other. */
    #[Test]
    public function each_provider_and_model_is_remembered_separately(): void
    {
        $gemini = $this->provider(true, 'gemini', 'gemini-2.5-flash');
        $ollama = $this->provider(false, 'ollama', 'llama3');
        $cache = new ProviderHealthCache();

        self::assertTrue($cache->isHealthy($gemini, 1_000.0));
        self::assertFalse($cache->isHealthy($ollama, 1_000.0));
        self::assertTrue($cache->isHealthy($gemini, 1_001.0));

        self::assertSame(1, $gemini->calls);
        self::assertSame(1, $ollama->calls);
    }

    /**
     * A throwing provider is a provider that did not answer. Caching that is the
     * whole point: otherwise the one that fails fastest costs the most.
     */
    #[Test]
    public function a_throwing_provider_is_unhealthy_and_not_re_asked(): void
    {
        $provider = $this->provider(true);
        $provider->throw = true;
        $cache = new ProviderHealthCache();

        self::assertFalse($cache->isHealthy($provider, 1_000.0));
        self::assertFalse($cache->isHealthy($provider, 1_005.0));
        self::assertSame(1, $provider->calls);
    }

    /**
     * Same name, same model, different host — a local Ollama and a remote one,
     * or staging against production. One is not evidence about the other.
     */
    #[Test]
    public function two_endpoints_are_two_facts(): void
    {
        $local = $this->provider(true, 'ollama', 'llama3', 'http://127.0.0.1:11434');
        $remote = $this->provider(false, 'ollama', 'llama3', 'http://95.216.199.200:11434');
        $cache = new ProviderHealthCache();

        self::assertTrue($cache->isHealthy($local, 1_000.0));
        self::assertFalse($cache->isHealthy($remote, 1_000.0), 'the remote host must be asked, not answered for');
        self::assertSame(1, $local->calls);
        self::assertSame(1, $remote->calls);
    }

    /**
     * The window starts when the answer arrives, not when we began asking.
     *
     * Stamping before the call makes a slow provider shorten its own cache
     * window by however long it took — with CURLOPT_TIMEOUT at five seconds,
     * the call that costs the most would be cached for the least.
     */
    #[Test]
    public function the_window_starts_when_the_answer_arrives(): void
    {
        $provider = $this->provider(true);
        $provider->delaySeconds = 0.05;
        $cache = new ProviderHealthCache();

        $before = microtime(true);
        $cache->isHealthy($provider);

        $stamp = (new \ReflectionProperty(ProviderHealthCache::class, 'answers'))
            ->getValue($cache)[array_key_first((new \ReflectionProperty(ProviderHealthCache::class, 'answers'))->getValue($cache))][1];

        self::assertGreaterThanOrEqual(
            $before + 0.05,
            $stamp,
            'the entry must be stamped after the call returned, not before it started',
        );
    }

    private function provider(
        bool $healthy,
        string $name = 'gemini',
        string $model = 'gemini-2.5-flash',
        string $endpoint = 'https://generativelanguage.googleapis.com',
    ): object {
        return new class($healthy, $name, $model, $endpoint) implements LlmProviderInterface {
            public int $calls = 0;
            public bool $throw = false;

            public float $delaySeconds = 0.0;

            public function __construct(
                public bool $healthy,
                private string $providerName,
                private string $providerModel,
                private string $providerEndpoint,
            ) {
            }

            public function healthCheck(): bool
            {
                $this->calls++;
                if ($this->delaySeconds > 0.0) {
                    usleep((int) ($this->delaySeconds * 1_000_000));
                }
                if ($this->throw) {
                    throw new \RuntimeException('provider unreachable');
                }

                return $this->healthy;
            }

            public function name(): string
            {
                return $this->providerName;
            }

            public function model(): string
            {
                return $this->providerModel;
            }

            public function baseUrl(): string
            {
                return $this->providerEndpoint;
            }

            public function complete(\Semitexa\Llm\Domain\Model\LlmRequest $request): \Semitexa\Llm\Domain\Model\LlmResponse
            {
                throw new \BadMethodCallException('these tests never complete a prompt');
            }
        };
    }
}
