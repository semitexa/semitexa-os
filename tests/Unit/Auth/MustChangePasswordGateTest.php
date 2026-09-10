<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Os\Application\Payload\Request\PasswordChangePayload;
use Semitexa\Os\Application\Payload\Request\SettingsAppPayload;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;
use Semitexa\Os\Pipeline\OsAdminGate;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Enum\UserStatus;
use Semitexa\Platform\User\Domain\Model\PlatformUser;

/**
 * A password somebody else chose is a password the account's owner has not.
 *
 * The gate is what makes the flag mean anything: a login redirect is a
 * suggestion and the address bar is not, so the check that matters is the one
 * every request passes through.
 *
 * Both directions are pinned. A test that only proved the console refuses would
 * pass on a version that also refused the two surfaces where the password can
 * actually be changed — which is a person locked out by the thing meant to let
 * them in.
 */
final class MustChangePasswordGateTest extends TestCase
{
    private function gate(): OsAdminGate
    {
        $gate = (new \ReflectionClass(OsAdminGate::class))->newInstanceWithoutConstructor();
        $policy = (new \ReflectionClass(OsAuthPolicy::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(OsAuthPolicy::class, 'authFlag'))->setValue($policy, '1');
        (new \ReflectionProperty(OsAdminGate::class, 'policy'))->setValue($gate, $policy);

        return $gate;
    }

    private function context(bool $mustChange, object $payload): RequestPipelineContext
    {
        $user = new PlatformUser(
            id: 'u1',
            email: 'editor@example.com',
            passwordHash: 'x',
            role: UserRole::Admin,
            status: UserStatus::Active,
            passwordIssuedByOperator: $mustChange,
        );

        $principal = (new \ReflectionClass(UserPrincipal::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(UserPrincipal::class, 'user'))->setValue($principal, $user);

        $context = (new \ReflectionClass(RequestPipelineContext::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RequestPipelineContext::class, 'requestDto'))->setValue($context, $payload);
        (new \ReflectionProperty(RequestPipelineContext::class, 'authResult'))
            ->setValue($context, new AuthResult(true, $principal));

        return $context;
    }

    private function ordinarySurface(): object
    {
        return new class implements OsContentSurfaceInterface {};
    }

    private function assertOpens(bool $mustChange, object $payload, string $what): void
    {
        try {
            $this->gate()->handle($this->context($mustChange, $payload));
        } catch (AccessDeniedException $e) {
            $this->fail($what . ' was refused: ' . $e->getMessage());
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_issued_password_closes_the_rest_of_the_console(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Replace the password your operator issued');

        $this->gate()->handle($this->context(true, $this->ordinarySurface()));
    }

    #[Test]
    public function the_two_surfaces_that_let_them_fix_it_stay_open(): void
    {
        // Without these the flag locks a person out with no way back in, and
        // the operator who set it becomes the only way out — which is the loop
        // the whole epic exists to break.
        $this->assertOpens(true, new SettingsAppPayload(), 'the settings form');
        $this->assertOpens(true, new PasswordChangePayload(), 'the password route');
    }

    #[Test]
    public function an_account_that_owes_nothing_is_unaffected(): void
    {
        $this->assertOpens(false, $this->ordinarySurface(), 'an ordinary surface');
    }

    #[Test]
    public function setting_a_password_of_their_own_clears_the_debt(): void
    {
        // Cleared in withPasswordHash rather than at each call site, so no path
        // that sets a hash can forget — a flag surviving its own resolution
        // would demand a change the person had just made.
        $user = new PlatformUser(
            id: 'u1',
            email: 'editor@example.com',
            passwordHash: 'old',
            passwordIssuedByOperator: true,
        );

        $this->assertTrue($user->isPasswordIssuedByOperator());
        $this->assertFalse($user->withPasswordHash('new-hash')->isPasswordIssuedByOperator());
        $this->assertTrue($user->withPasswordIssuedByOperator('new-hash')->isPasswordIssuedByOperator());
    }
}
