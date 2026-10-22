<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Tenant\DefaultTenantContext;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;
use Semitexa\Os\Pipeline\OsAdminGate;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Model\PlatformUser;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * The console's data follows the request's site, and the gate only asked the
 * role: site A's admin signed in at site B's host and read and wrote B's
 * pages, chat and prompts, and on the root site the operator's own.
 */
final class OsConsoleSiteBindingTest extends TestCase
{
    use BuildsContainerManagedObjects;

    #[Test]
    public function a_site_s_user_is_admitted_on_that_site_only(): void
    {
        foreach ([UserRole::Admin, UserRole::Editor] as $role) {
            $this->assertAdmitted($role, 'museum', self::site('museum'));
            $this->assertRefused($role, 'museum', self::site('clinic'));
            $this->assertRefused($role, 'museum', new DefaultTenantContext());
        }
    }

    #[Test]
    public function an_owner_is_admitted_anywhere_and_without_tenancy_the_user_s_own_site_counts(): void
    {
        $this->assertAdmitted(UserRole::Owner, '', self::site('clinic'));
        $this->assertAdmitted(UserRole::Owner, '', new DefaultTenantContext());
        $this->assertAdmitted(UserRole::Admin, 'museum', null);
    }

    /** An owner made with a site before user:add refused one keeps the root site's console. */
    #[Test]
    public function an_owner_bound_to_a_site_still_opens_the_root_site(): void
    {
        $this->assertAdmitted(UserRole::Owner, 'museum', new DefaultTenantContext());
    }

    private function assertAdmitted(UserRole $role, string $site, ?TenantContextInterface $request): void
    {
        $this->gate($request)->handle(self::context($role, $site));
        $this->addToAssertionCount(1);
    }

    private function assertRefused(UserRole $role, string $site, TenantContextInterface $request): void
    {
        try {
            $this->gate($request)->handle(self::context($role, $site));
            self::fail("{$role->value} of {$site} was admitted on another site");
        } catch (AccessDeniedException $e) {
            self::assertStringContainsString('another site', $e->getMessage());
        }
    }

    private function gate(?TenantContextInterface $request): OsAdminGate
    {
        $policy = $this->createWithDependencies(OsAuthPolicy::class, ['authFlag' => '1']);
        $dependencies = ['policy' => $policy];
        if ($request !== null) {
            $dependencies['tenants'] = new class ($request) implements TenantContextStoreInterface {
                public function __construct(private TenantContextInterface $context) {}
                public function get(): TenantContextInterface { return $this->context; }
                public function tryGet(): ?TenantContextInterface { return $this->context; }
                public function set(TenantContextInterface $context): void {}
                public function clear(): void {}
            };
        }

        return $this->createWithDependencies(OsAdminGate::class, $dependencies);
    }

    private static function context(UserRole $role, string $site): RequestPipelineContext
    {
        $principal = new UserPrincipal(new PlatformUser(id: 'u1', email: 'a@example.test', passwordHash: 'x', tenantId: $site, role: $role));
        $context = (new \ReflectionClass(RequestPipelineContext::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RequestPipelineContext::class, 'requestDto'))->setValue($context, new class implements OsContentSurfaceInterface {});
        (new \ReflectionProperty(RequestPipelineContext::class, 'authResult'))->setValue($context, new AuthResult(true, $principal));

        return $context;
    }

    private static function site(string $id): TenantContextInterface
    {
        return new class ($id) implements TenantContextInterface {
            public function __construct(private string $id) {}
            public function getTenantId(): string { return $this->id; }
            public function isDefault(): bool { return false; }
            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface { return null; }
            public function hasLayer(TenantLayerInterface $layer): bool { return false; }
        };
    }
}
