<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Queue\DeadLetter;
use Semitexa\Core\Queue\QueueInspection;
use Semitexa\Core\Queue\QueueInspectorInterface;
use Semitexa\Core\Queue\QueueStatsReport;
use Semitexa\Core\Queue\QueueStatsStoreInterface;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Os\Application\Handler\PayloadHandler\QueuesAppHandler;
use Semitexa\Os\Application\Payload\Request\QueuesAppPayload;
use Semitexa\Os\Application\Payload\Session\OsAdminSessionSegment;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Model\PlatformUser;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * The queue dashboard: queue:stats and queue:failed were the only view of
 * the queues, and only in a terminal.
 */
final class QueuesAppHandlerTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private const TRANSPORT = 'queues-app-test';

    private ?SessionInterface $session = null;

    protected function setUp(): void
    {
        putenv('EVENTS_TRANSPORT=' . self::TRANSPORT);
        putenv('QUEUE_STATS_STORE=off');
        QueueStatsReport::forgetStore();
        QueuesAppTestTransport::$unreachable = false;
        QueueTransportRegistry::register(self::TRANSPORT, new class implements QueueTransportFactoryInterface {
            public function create(): QueueTransportInterface
            {
                return new QueuesAppTestTransport();
            }
        });
    }

    protected function tearDown(): void
    {
        putenv('EVENTS_TRANSPORT');
        putenv('QUEUE_STATS_STORE');
        QueueStatsReport::forgetStore();
        QueueTransportRegistry::reset();
    }

    #[Test]
    public function the_page_lists_every_queue_and_its_dead_letters_escaped(): void
    {
        [$html, $status] = $this->render(new OsSkillScope());

        self::assertSame(200, $status);
        self::assertStringContainsString('<h2>Queues</h2>', $html);
        self::assertStringContainsString(self::TRANSPORT . '/events', $html);
        self::assertStringContainsString('App\\Job\\&lt;script&gt;', $html);
        self::assertStringContainsString('boom &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('secret-payload', $html);
        self::assertStringNotContainsString('Not read', $html);
        self::assertStringNotContainsString('__BODY__', $html);
    }

    #[Test]
    public function a_queue_that_cannot_be_read_is_said_and_not_shown_as_empty(): void
    {
        QueuesAppTestTransport::$unreachable = true;

        [$html] = $this->render(new OsSkillScope());

        self::assertStringContainsString('could not be read: broker down', $html);
        self::assertMatchesRegularExpression('#Not read: [^<]*' . self::TRANSPORT . '/events#', $html);
        self::assertStringNotContainsString('Nothing dead-lettered', $html);
    }

    #[Test]
    public function counters_that_cannot_be_read_are_unknown_and_leave_the_queues_readable(): void
    {
        $store = new class implements QueueStatsStoreInterface {
            public function add(string $queue, int $minute, array $deltas): void {}
            public function sum(string $queue, int $fromMinute, int $toMinute): array
            {
                throw new \RuntimeException('redis gone');
            }
            public function describe(): string
            {
                return 'redis';
            }
        };
        (new \ReflectionProperty(QueueStatsReport::class, 'store'))->setValue(null, $store);
        (new \ReflectionProperty(QueueStatsReport::class, 'storeResolved'))->setValue(null, true);

        [$html] = $this->render(new OsSkillScope());

        self::assertStringContainsString('could not be read: redis gone', $html);
        self::assertStringContainsString('<td class="num">?</td>', $html, 'an unknown count is not a zero');
        self::assertStringContainsString('<td class="num">3</td>', $html, 'the depth is still read');
        self::assertStringContainsString('boom &lt;script&gt;', $html, 'and so are the dead letters');
        self::assertStringNotContainsString('Not read', $html);
    }

    #[Test]
    public function the_owner_is_let_in(): void
    {
        [$html, $status] = $this->render($this->scope(UserRole::Owner, ''));

        self::assertSame(200, $status);
        self::assertStringContainsString(self::TRANSPORT . '/events', $html);
    }

    #[Test]
    public function a_tenant_admin_is_refused_because_the_queues_hold_every_tenant(): void
    {
        [$html, $status] = $this->render($this->scope(UserRole::Admin, 'museum'));

        self::assertSame(403, $status);
        self::assertStringNotContainsString(self::TRANSPORT, $html);
        self::assertStringNotContainsString('boom', $html);
    }

    #[Test]
    public function a_stranger_is_refused(): void
    {
        $scope = new OsSkillScope();
        // Auth required (web mode) and nobody signed in: no scope at all.
        (new \ReflectionProperty($scope, 'policy'))->setValue($scope, new OsAuthPolicy());

        [, $status] = $this->render($scope);

        self::assertSame(403, $status);
    }

    #[Test]
    public function a_handler_built_without_its_scope_refuses(): void
    {
        [, $status] = $this->render(null);

        self::assertSame(403, $status);
    }

    /** @return array{string, int} */
    private function render(?OsSkillScope $scope): array
    {
        $handler = new QueuesAppHandler();
        (new \ReflectionProperty($handler, 'inspection'))->setValue($handler, ContainerFactory::get()->get(QueueInspection::class));
        if ($scope !== null) {
            (new \ReflectionProperty($handler, 'scope'))->setValue($handler, $scope);
        }
        if ($this->session !== null) {
            (new \ReflectionProperty($handler, 'session'))->setValue($handler, $this->session);
        }

        $response = $handler->handle(new QueuesAppPayload(), new ResourceResponse());

        return [$response->getContent(), $response->getStatusCode()];
    }
    /** Auth required, and a signed-in user of $role on $tenantId. */
    private function scope(UserRole $role, string $tenantId): OsSkillScope
    {
        $user = new PlatformUser(id: 'u1', email: 'a@example.test', passwordHash: 'x', tenantId: $tenantId, role: $role);
        $users = new class ($user) implements PlatformUserRepositoryInterface {
            public function __construct(private PlatformUser $user) {}
            public function update(PlatformUser $user): void {}
            public function save(PlatformUser $user): void {}
            public function findById(string $id): ?PlatformUser { return $this->user->getId() === $id ? $this->user : null; }
            public function findByEmail(string $email, ?string $tenantId = null): ?PlatformUser { return $this->user; }
            public function findAll(?string $tenantId = null, bool $includeDisabled = false): array { return [$this->user]; }
            public function delete(string $id): void {}
        };
        $segment = new OsAdminSessionSegment();
        $segment->signIn('u1');
        $this->session = new class ($segment) implements SessionInterface {
            public function __construct(private OsAdminSessionSegment $segment) {}
            public function getPayload(string $payloadClass): object { return $this->segment; }
            public function setPayload(object $payload): void {}
            public function regenerate(): void {}
            public function get(string $key, mixed $default = null): mixed { return $default; }
            public function set(string $key, mixed $value): void {}
            public function has(string $key): bool { return false; }
            public function remove(string $key): void {}
            public function clear(): void {}
            public function getId(): string { return 'sess'; }
            public function flash(string $key, mixed $value): void {}
            public function getFlash(string $key, mixed $default = null): mixed { return $default; }
            public function save(): void {}
        };

        return $this->createWithDependencies(OsSkillScope::class, [
            'policy' => new OsAuthPolicy(),
            'admins' => $this->createWithDependencies(OsAdminSession::class, ['users' => $users]),
        ]);
    }
}

final class QueuesAppTestTransport implements QueueTransportInterface, QueueInspectorInterface
{
    public static bool $unreachable = false;

    public function publish(string $queueName, string $payload): void {}

    public function consume(string $queueName, callable $callback): void {}

    public function depth(string $queue): int
    {
        if (self::$unreachable) {
            throw new \RuntimeException('broker down');
        }

        return 3;
    }

    public function oldestAgeSeconds(string $queue): ?int
    {
        return self::$unreachable ? throw new \RuntimeException('broker down') : 42;
    }

    public function deadLetters(string $queue, int $limit = 20): array
    {
        if (self::$unreachable) {
            throw new \RuntimeException('broker down');
        }

        return [new DeadLetter('1', $queue, 'App\\Job\\<script>', 5, 'boom <script>alert(1)</script>', new \DateTimeImmutable('2026-10-21 08:00:00'), 'secret-payload', true)];
    }

    public function retryDeadLetter(string $queue, string $id): bool
    {
        return false;
    }

    public function forgetDeadLetter(string $queue, string $id): bool
    {
        return false;
    }
}
