<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\RateLimit\InMemoryRateLimitStore;
use Semitexa\Core\RateLimit\RateLimiter;
use Semitexa\Core\RateLimit\RateLimitStorage;
use Semitexa\Core\Request;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Os\Application\Handler\PayloadHandler\OsLoginSubmitHandler;
use Semitexa\Os\Application\Payload\Request\OsLoginSubmitPayload;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Platform\User\Application\Service\PasswordHasher;
use Semitexa\Platform\User\Application\Service\UserAuthenticator;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;
use Semitexa\Platform\User\Domain\Model\PlatformUser;

/**
 * The lockout counts guesses at one account; nothing counted one client
 * guessing across many. Failed sign-ins are counted per client address — in
 * the handler, after the CSRF check, so only real failures count.
 */
final class OsLoginFailureLimitTest extends TestCase
{
    #[Test]
    public function one_address_failing_ten_times_in_a_minute_is_told_when_to_try_again(): void
    {
        $session = new Session(str_repeat('a', 32), $this->sessions(), 'semitexa_session');
        $handler = $this->handler($session);
        $token = self::formToken($session);

        foreach (range(1, 10) as $i) {
            self::assertSame('Wrong email or password.', self::error($handler->handle(self::payload("user{$i}@example.test", $token), new OsLoginResource())));
        }
        $refused = self::error($handler->handle(self::payload('user11@example.test', $token), new OsLoginResource()));

        self::assertStringStartsWith('Too many failed sign-ins from your network. Try again in 1 minute', $refused);
    }

    #[Test]
    public function a_request_without_a_valid_form_token_does_not_count(): void
    {
        $session = new Session(str_repeat('b', 32), $this->sessions(), 'semitexa_session');
        $handler = $this->handler($session);
        $token = self::formToken($session);

        foreach (range(1, 20) as $ignored) {
            $handler->handle(self::payload('x@example.test', 'forged'), new OsLoginResource());
        }

        self::assertSame('Wrong email or password.', self::error($handler->handle(self::payload('x@example.test', $token), new OsLoginResource())));
    }

    private function handler(Session $session): OsLoginSubmitHandler
    {
        $policy = new OsAuthPolicy();
        (new \ReflectionProperty($policy, 'authFlag'))->setValue($policy, 'required');

        $authenticator = new UserAuthenticator();
        foreach (['users' => $this->noUsers(), 'hasher' => new PasswordHasher(), 'maxAttempts' => 5, 'lockSeconds' => 900] as $property => $value) {
            (new \ReflectionProperty($authenticator, $property))->setValue($authenticator, $value);
        }

        $storage = new RateLimitStorage();
        $storage->use(new InMemoryRateLimitStore());
        $limiter = new RateLimiter();
        (new \ReflectionProperty($limiter, 'storage'))->setValue($limiter, $storage);
        (new \ReflectionProperty($limiter, 'request'))->setValue($limiter, new Request('POST', '/os/login', [], [], [], ['remote_addr' => '203.0.113.7'], []));

        $handler = new OsLoginSubmitHandler();
        foreach (['session' => $session, 'policy' => $policy, 'authenticator' => $authenticator, 'limiter' => $limiter] as $property => $value) {
            (new \ReflectionProperty($handler, $property))->setValue($handler, $value);
        }

        return $handler;
    }

    /** What the sign-in page puts in its form. */
    private static function formToken(Session $session): string
    {
        $token = CsrfToken::generate();
        $session->setPayload($token);

        return $token->getValue();
    }

    private static function payload(string $email, string $token): OsLoginSubmitPayload
    {
        $payload = new OsLoginSubmitPayload();
        $payload->setEmail($email);
        $payload->setPassword('not-the-password');
        $payload->setCsrfToken($token);

        return $payload;
    }

    private static function error(OsLoginResource $resource): string
    {
        return (string) ($resource->getRenderContext()['error'] ?? '');
    }

    private function noUsers(): PlatformUserRepositoryInterface
    {
        return new class implements PlatformUserRepositoryInterface {
            public function findById(string $id): ?PlatformUser { return null; }
            public function findByEmail(string $email, ?string $tenantId = null): ?PlatformUser { return null; }
            public function findAll(?string $tenantId = null, bool $includeDisabled = false): array { return []; }
            public function save(PlatformUser $user): void {}
            public function update(PlatformUser $user): void {}
            public function delete(string $id): void {}
        };
    }

    private function sessions(): SessionHandlerInterface
    {
        return new class implements SessionHandlerInterface {
            public function read(string $sessionId): array { return []; }
            public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void {}
            public function destroy(string $sessionId): void {}
        };
    }
}
