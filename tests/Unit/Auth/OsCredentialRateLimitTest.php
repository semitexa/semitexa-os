<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\RateLimit;
use Semitexa\Core\RateLimit\RateLimitKey;
use Semitexa\Os\Application\Payload\Request\OsLoginSubmitPayload;
use Semitexa\Os\Application\Payload\Request\PasswordChangePayload;

/**
 * The two places a password is checked are limited per client, not only by
 * the account's lockout: one client guessing across many addresses was never
 * counted, and the current-password check of a borrowed session was not either.
 */
final class OsCredentialRateLimitTest extends TestCase
{
    #[Test]
    public function the_password_change_is_limited_per_user_and_the_sign_in_route_is_not(): void
    {
        self::assertSame([], self::limits(OsLoginSubmitPayload::class), 'failed sign-ins are counted in the handler (OsLoginFailureLimitTest)');
        self::assertSame([[5, 300, RateLimitKey::User]], self::limits(PasswordChangePayload::class));
    }

    /** @return list<array{int, int, RateLimitKey}> */
    private static function limits(string $payload): array
    {
        return array_map(
            static function (\ReflectionAttribute $attribute): array {
                $limit = $attribute->newInstance();

                return [$limit->limit, $limit->perSeconds, $limit->by];
            },
            (new \ReflectionClass($payload))->getAttributes(RateLimit::class),
        );
    }
}
