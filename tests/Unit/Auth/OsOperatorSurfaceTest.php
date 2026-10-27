<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Llm\Application\Service\TenantSkillScope;
use Semitexa\Llm\Domain\Model\SkillScope;
use Semitexa\Os\Application\Handler\PayloadHandler\OsLoginHandler;
use Semitexa\Os\Application\Handler\PayloadHandler\TerminalRunHandler;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\OsLoginPayload;
use Semitexa\Os\Application\Payload\Request\PromptsAppPayload;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;
use Semitexa\Os\Application\Payload\Request\QueuesAppPayload;
use Semitexa\Os\Application\Payload\Request\TerminalAppPayload;
use Semitexa\Os\Application\Payload\Request\TerminalRunPayload;
use Semitexa\Os\Application\Payload\Request\UpdatesAppPayload;
use Semitexa\Os\Application\Payload\Session\OsAdminSessionSegment;
use Semitexa\Os\Application\Service\OsAdminSession;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Application\Service\OsCalendarOwner;
use Semitexa\Os\Application\Service\OsConsoleAccess;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Domain\Model\SkillEntry;
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Os\Application\Service\OsUpdatesSkill;
use Semitexa\Os\Application\Service\TerminalSkill;
use Semitexa\Os\Application\Service\UpdatesSkill;
use Semitexa\Os\Auth\OsAdminAuthHandler;
use Semitexa\Os\Auth\OsDesktopAuthHandler;
use Semitexa\Os\Auth\OsDesktopPrincipal;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;
use Semitexa\Os\Domain\Contract\OsOperatorSurfaceInterface;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Os\Domain\Enum\WindowMode;
use Semitexa\Os\Pipeline\OsAdminGate;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Model\PlatformUser;
use Semitexa\PlatformUi\Application\Console\Command\Skins\ExplainPromptCommand;
use Semitexa\PlatformUi\Application\Console\Command\Skins\GenerateSkinCommand;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * The terminal, the update journal and the queues are the install's: a site's
 * admin opened the terminal and ran the install's console skills, and read the
 * installed versions and the update runs. With sign-in off, every protected
 * console route answered 401, because nobody was signed in to let through.
 */
final class OsOperatorSurfaceTest extends TestCase
{
    use BuildsContainerManagedObjects;

    protected function tearDown(): void
    {
        AuthContextStore::clear();
    }

    #[Test]
    public function only_an_owner_opens_an_operator_surface(): void
    {
        $surface = new class implements OsOperatorSurfaceInterface {};

        $this->gate()->handle(self::context(UserRole::Owner, '', $surface));
        foreach ([UserRole::Admin, UserRole::Editor] as $role) {
            try {
                $this->gate()->handle(self::context($role, 'museum', $surface));
                self::fail("{$role->value} opened an operator surface");
            } catch (AccessDeniedException $e) {
                self::assertStringContainsString('owner', $e->getMessage());
            }
        }

        // The rest of the console is still an admin's.
        $this->gate()->handle(self::context(UserRole::Admin, 'museum', new class implements OsSurfacePayloadInterface {}));
    }

    #[Test]
    public function the_install_s_surfaces_are_operator_surfaces_and_a_site_s_prompts_are_not(): void
    {
        foreach ([TerminalAppPayload::class, TerminalRunPayload::class, UpdatesAppPayload::class, QueuesAppPayload::class] as $class) {
            self::assertTrue(is_a($class, OsOperatorSurfaceInterface::class, true), $class);
        }
        // Prompt overrides are kept per site: a site's admin edits their own.
        self::assertFalse(is_a(PromptsAppPayload::class, OsOperatorSurfaceInterface::class, true));
        self::assertTrue(OsAdminGate::roleOpens(UserRole::Admin, PromptsAppPayload::class));
    }

    #[Test]
    public function their_skills_are_out_of_a_site_admin_s_manifest(): void
    {
        $scope = new TenantSkillScope();
        $operator = $scope->classesFor(SkillScope::unrestricted());
        $site = $scope->classesFor(SkillScope::forTenant('museum'));

        foreach ([TerminalSkill::class, UpdatesSkill::class, OsUpdatesSkill::class, GenerateSkinCommand::class, ExplainPromptCommand::class] as $class) {
            self::assertContains($class, $operator, $class);
            self::assertNotContains($class, $site, $class);
        }
    }

    #[Test]
    public function with_sign_in_off_the_console_s_routes_see_the_person_at_the_machine(): void
    {
        $handler = $this->authHandler('0');

        $result = $handler->handle(new class implements OsSurfacePayloadInterface {});
        self::assertNotNull($result);
        self::assertTrue($result->success);
        self::assertInstanceOf(OsDesktopPrincipal::class, $result->user);

        // Any other route of the site sees nobody, as before.
        self::assertNull($handler->handle(new \stdClass()));
        // And with sign-in on, nobody signed in is nobody.
        self::assertNull($this->authHandler('1')->handle(new class implements OsSurfacePayloadInterface {}));
    }

    /**
     * Sign-in off on a desktop install was off for every client: anyone who
     * reached the port (the LAN, a published container port) was the person
     * at the machine, with the console. Now only the machine's own browser.
     */
    #[Test]
    public function with_sign_in_off_only_the_machine_s_own_browser_is_the_person_at_it(): void
    {
        $surface = new class implements OsSurfacePayloadInterface {};
        $from = static fn (string $address): Request => new Request('GET', '/os/app/notes', [], [], [], ['remote_addr' => $address], []);
        $before = CurrentRequestStore::get();
        try {
            CurrentRequestStore::set($from('192.168.1.20'));
            self::assertNull($this->authHandler('0')->handle($surface), 'another machine on the LAN');
            self::assertTrue(self::policy('0')->isRequired(), 'and it is asked to sign in');

            CurrentRequestStore::set($from('127.0.0.1'));
            self::assertInstanceOf(OsDesktopPrincipal::class, $this->authHandler('0')->handle($surface)?->user);
            self::assertFalse(self::policy('0')->isRequired());

            // The app in a container sees the host's browser come from the gateway.
            CurrentRequestStore::set($from('172.18.0.1'));
            self::assertNull($this->authHandler('0')->handle($surface), 'a gateway nobody named');
            $handler = $this->authHandler('0');
            $policy = (new \ReflectionProperty($handler, 'policy'))->getValue($handler);
            (new \ReflectionProperty($policy, 'desktopClients'))->setValue($policy, '10.0.0.5, 172.18.0.0/16');
            self::assertInstanceOf(OsDesktopPrincipal::class, $handler->handle($surface)?->user, 'named in SEMITEXA_OS_DESKTOP_CLIENTS');
        } finally {
            $before === null ? CurrentRequestStore::clear() : CurrentRequestStore::set($before);
        }
    }

    #[Test]
    public function a_skill_asks_the_gate_s_rule_before_opening_a_window(): void
    {
        $surface = (new class implements OsSurfacePayloadInterface {})::class;
        $content = (new class implements OsContentSurfaceInterface {})::class;
        $access = $this->createWithDependencies(OsConsoleAccess::class, ['policy' => self::policy('1')]);

        self::assertFalse($access->opens($surface), 'nobody signed in');

        AuthContextStore::setUser(new UserPrincipal(self::user(UserRole::Editor, 'museum')));
        self::assertFalse($access->opens($surface));
        self::assertTrue($access->opens($content));

        AuthContextStore::setUser(new UserPrincipal(self::user(UserRole::Admin, 'museum')));
        self::assertTrue($access->opens($surface));
        self::assertFalse($access->opens((new class implements OsOperatorSurfaceInterface {})::class));

        AuthContextStore::clear();
        self::assertTrue($this->createWithDependencies(OsConsoleAccess::class, ['policy' => self::policy('0')])->opens($surface));
    }

    #[Test]
    public function another_site_s_user_is_nobody_to_the_shell_and_the_skill_scope(): void
    {
        $user = self::user(UserRole::Admin, 'museum');

        self::assertSame($user, $this->admins($user, self::site('museum'))->currentHere(self::session()));
        self::assertNull($this->admins($user, self::site('clinic'))->currentHere(self::session()));
        self::assertSame($user, $this->admins($user, null)->currentHere(self::session()), 'without tenancy their site is the only one');

        $scope = $this->createWithDependencies(OsSkillScope::class, [
            'policy' => self::policy('1'),
            'admins' => $this->admins($user, self::site('clinic')),
        ]);
        self::assertNull($scope->forSession(self::session()));
    }

    #[Test]
    public function another_site_s_user_is_presented_to_no_route_and_shown_the_sign_in_form(): void
    {
        $user = self::user(UserRole::Admin, 'museum');

        foreach (['clinic' => false, 'museum' => true] as $site => $ours) {
            $auth = $this->adminAuthHandler();
            (new \ReflectionProperty($auth, 'admins'))->setValue($auth, $this->admins($user, self::site($site)));
            $auth->setSession(self::session());
            $result = $auth->handle(new \stdClass());
            self::assertSame($ours, $result !== null, "presented on {$site}");

            $login = new OsLoginHandler();
            foreach (['session' => self::session(), 'admins' => $this->admins($user, self::site($site)), 'policy' => self::policy('1')] as $property => $value) {
                (new \ReflectionProperty($login, $property))->setValue($login, $value);
            }
            $page = $login->handle(new OsLoginPayload(), new OsLoginResource());
            // This site's user is sent on to the shell; another site's would be
            // sent there too, and back.
            self::assertSame($ours ? '/os' : null, $page->getRedirectUrl(), "sign-in page on {$site}");
        }
    }

    /** The Terminal posted to /os/skill, which knows only web and ui skills: every command was "Unknown skill". */
    #[Test]
    public function the_terminal_runs_from_its_own_console_manifest(): void
    {
        $handler = new TerminalRunHandler();
        (new \ReflectionProperty($handler, 'skills'))->setValue($handler, new TenantSkillScope());
        (new \ReflectionProperty($handler, 'session'))->setValue($handler, self::session());
        $payload = new TerminalRunPayload();
        $payload->setSkill('no-such-skill');

        (new \ReflectionProperty($handler, 'skillScope'))->setValue($handler, $this->createWithDependencies(OsSkillScope::class, ['policy' => self::policy('1')]));
        self::assertSame(401, $handler->handle($payload, new ResourceResponse())->getStatusCode(), 'nobody signed in');

        (new \ReflectionProperty($handler, 'skillScope'))->setValue($handler, $this->createWithDependencies(OsSkillScope::class, ['policy' => self::policy('0')]));
        $response = $handler->handle($payload, new ResourceResponse());
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString("Unknown console skill 'no-such-skill'", (string) $response->getContent());
    }

    /**
     * The positive side: a console-only skill (contracts:list, from core's
     * discovered commands) runs. Through its real manifest and the real
     * SkillExecutor; only the console command is a stand-in, so nothing is
     * listed or written. Asked on any other channel, it is unknown, which the
     * "no-such-skill" check above could not tell apart.
     */
    #[Test]
    public function the_terminal_runs_a_console_only_skill(): void
    {
        $console = new \Symfony\Component\Console\Application();
        $console->setAutoExit(false);
        $console->add(new class ('contracts:list') extends \Symfony\Component\Console\Command\Command {
            protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
            {
                $output->write('listed');

                return 0;
            }
        });
        $runner = (new \ReflectionClass(\Semitexa\Os\Application\Service\SkillLoopRunner::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($runner, 'executor'))->setValue($runner, new \Semitexa\Llm\Application\Service\SkillExecutor($console));
        $handler = new TerminalRunHandler();
        (new \ReflectionProperty($handler, 'skills'))->setValue($handler, new TenantSkillScope());
        (new \ReflectionProperty($handler, 'session'))->setValue($handler, self::session());
        (new \ReflectionProperty($handler, 'runner'))->setValue($handler, $runner);
        (new \ReflectionProperty($handler, 'skillScope'))->setValue($handler, $this->createWithDependencies(OsSkillScope::class, ['policy' => self::policy('0')]));
        $payload = new TerminalRunPayload();
        $payload->setSkill('contracts:list');

        $response = $handler->handle($payload, new ResourceResponse());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['skill' => 'contracts:list', 'output' => 'listed', 'error' => null, 'exit_code' => 0], json_decode((string) $response->getContent(), true));
        self::assertNull((new TenantSkillScope())->manifestFor(SkillScope::unrestricted(), ['web'])->findSkill('contracts:list'), 'a console-only skill: on the web channel it is unknown');
    }

    /** An editor was offered Prompts by the shell, the planner and the dialog opener, and its window answered 403. */
    #[Test]
    public function a_window_the_role_is_refused_is_left_out_of_the_manifest(): void
    {
        $routes = new class extends AttributeDiscovery {
            public function __construct() {}

            public function findRoute(string $path, string $method = 'GET'): ?array
            {
                return match ($path) {
                    '/os/app/prompts' => ['class' => PromptsAppPayload::class],
                    '/os/app/notes' => ['class' => \Semitexa\Os\Application\Payload\Request\NotesAppPayload::class],
                    default => null,
                };
            }
        };
        $access = $this->createWithDependencies(OsConsoleAccess::class, ['policy' => self::policy('1'), 'routes' => $routes]);
        $manifest = (new SkillManifest('semitexa.ai-skills/v1', 'now', [
            self::uiSkill('Prompts', '/os/app/prompts'),
            self::uiSkill('Notes', '/os/app/notes?x=1'),
            self::uiSkill('Elsewhere', '/somewhere'),
        ]))->forChannels(['ui']);

        AuthContextStore::setUser(new UserPrincipal(self::user(UserRole::Editor, 'museum')));
        self::assertSame(['Notes', 'Elsewhere'], array_map(static fn (SkillEntry $s): string => $s->name, $access->narrow($manifest)->skills()));
        self::assertFalse($access->opensEntry('/os/app/prompts'));

        AuthContextStore::setUser(new UserPrincipal(self::user(UserRole::Admin, 'museum')));
        self::assertCount(3, $access->narrow($manifest)->skills());
    }

    /** The console's Calendar reads /platform/calendar/events*, which answered 401 on a desktop install. */
    #[Test]
    public function on_a_desktop_install_every_protected_route_sees_the_person_and_a_public_one_nobody(): void
    {
        $handler = $this->authHandler('0');

        self::assertInstanceOf(OsDesktopPrincipal::class, $handler->handle(new \Semitexa\PlatformUi\Application\Payload\Request\CalendarEventsFeedPayload())?->user);
        self::assertNull($handler->handle(new OsLoginPayload()), 'the sign-in page is public');
    }

    /** An event made in chat went to the shared calendar, which the app never shows a signed-in person. */
    #[Test]
    public function the_calendar_skills_use_the_person_s_own_calendar(): void
    {
        self::assertNull(OsCalendarOwner::current(), 'a command: the shared calendar');
        AuthContextStore::setUser(new UserPrincipal(self::user(UserRole::Editor, 'museum')));
        self::assertSame('u1', OsCalendarOwner::current());
        AuthContextStore::setUser(new OsDesktopPrincipal());
        self::assertSame('os-desktop', OsCalendarOwner::current());
    }

    private static function uiSkill(string $name, string $entry): SkillEntry
    {
        return new SkillEntry(
            name: $name, sourceCommand: null, summary: $name, useWhen: $name, avoidWhen: '',
            riskLevel: AiRiskLevel::Low, confirmation: AiConfirmationMode::Never, supportsDryRun: false,
            argumentPolicy: AiArgumentPolicy::None, inputs: [], channels: ['ui'], executionKind: AiExecutionKind::DirectCommand,
            entry: $entry,
        );
    }

    #[Test]
    public function the_dialog_opener_refuses_a_window_the_role_is_refused(): void
    {
        $editor = self::user(UserRole::Editor, 'museum');
        AuthContextStore::setUser(new UserPrincipal($editor));
        $routes = new class extends AttributeDiscovery {
            public function __construct() {}

            public function findRoute(string $path, string $method = 'GET'): ?array
            {
                return $path === '/os/app/prompts' ? ['class' => PromptsAppPayload::class] : null;
            }
        };
        $handler = new \Semitexa\Os\Application\Handler\PayloadHandler\OpenDialogHandler();
        foreach ([
            'session' => self::session(),
            'skillScope' => $this->createWithDependencies(OsSkillScope::class, ['policy' => self::policy('1'), 'admins' => $this->admins($editor, null)]),
            'skills' => new TenantSkillScope(),
            'access' => $this->createWithDependencies(OsConsoleAccess::class, ['policy' => self::policy('1'), 'routes' => $routes]),
        ] as $property => $value) {
            (new \ReflectionProperty($handler, $property))->setValue($handler, $value);
        }
        $payload = new \Semitexa\Os\Application\Payload\Request\OpenDialogPayload();
        $payload->setSkill('Prompts');

        $response = $handler->handle($payload, new ResourceResponse());

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('not open to your account', (string) $response->getContent());
    }

    /** The desktop person was presented ahead of a real sign-in on every protected route. */
    #[Test]
    public function the_desktop_person_comes_last_after_anyone_who_signed_in(): void
    {
        $priority = static fn (string $class): int => (new \ReflectionClass($class))->getAttributes(\Semitexa\Auth\Attribute\AsAuthHandler::class)[0]->newInstance()->priority;

        foreach ([OsAdminAuthHandler::class, \Semitexa\Auth\Application\Service\SessionAuthHandler::class, \Semitexa\Api\Auth\MachineAuthHandler::class] as $earlier) {
            if (class_exists($earlier)) {
                self::assertLessThan($priority(OsDesktopAuthHandler::class), $priority($earlier), $earlier);
            }
        }
    }

    private function gate(): OsAdminGate
    {
        return $this->createWithDependencies(OsAdminGate::class, ['policy' => self::policy('1')]);
    }

    private function authHandler(string $flag): OsDesktopAuthHandler
    {
        $handler = new OsDesktopAuthHandler();
        (new \ReflectionProperty($handler, 'policy'))->setValue($handler, self::policy($flag));

        return $handler;
    }

    private function adminAuthHandler(): OsAdminAuthHandler
    {
        return new OsAdminAuthHandler();
    }

    private static function policy(string $flag): OsAuthPolicy
    {
        // Sign-in is off only on a desktop install.
        $policy = new OsAuthPolicy();
        (new \ReflectionProperty($policy, 'authFlag'))->setValue($policy, $flag);
        (new \ReflectionProperty($policy, 'windowMode'))->setValue($policy, $flag === '0' ? WindowMode::Os : WindowMode::Web);

        return $policy;
    }

    private static function user(UserRole $role, string $site): PlatformUser
    {
        return new PlatformUser(id: 'u1', email: 'a@example.test', passwordHash: 'x', tenantId: $site, role: $role);
    }

    private static function context(UserRole $role, string $site, object $surface): RequestPipelineContext
    {
        $context = (new \ReflectionClass(RequestPipelineContext::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RequestPipelineContext::class, 'requestDto'))->setValue($context, $surface);
        (new \ReflectionProperty(RequestPipelineContext::class, 'authResult'))->setValue($context, new AuthResult(true, new UserPrincipal(self::user($role, $site))));

        return $context;
    }

    private function admins(PlatformUser $user, ?TenantContextInterface $site): OsAdminSession
    {
        $users = new class ($user) implements PlatformUserRepositoryInterface {
            public function __construct(private PlatformUser $user) {}
            public function update(PlatformUser $user): void {}
            public function save(PlatformUser $user): void {}
            public function findById(string $id): ?PlatformUser { return $this->user->getId() === $id ? $this->user : null; }
            public function findByEmail(string $email, ?string $tenantId = null): ?PlatformUser { return $this->user; }
            public function findAll(?string $tenantId = null, bool $includeDisabled = false): array { return [$this->user]; }
            public function delete(string $id): void {}
        };
        $dependencies = ['users' => $users];
        if ($site !== null) {
            $dependencies['tenants'] = new class ($site) implements TenantContextStoreInterface {
                public function __construct(private TenantContextInterface $context) {}
                public function get(): TenantContextInterface { return $this->context; }
                public function tryGet(): ?TenantContextInterface { return $this->context; }
                public function set(TenantContextInterface $context): void {}
                public function clear(): void {}
            };
        }

        return $this->createWithDependencies(OsAdminSession::class, $dependencies);
    }

    private static function session(): SessionInterface
    {
        $segment = new OsAdminSessionSegment();
        $segment->signIn('u1');

        return new class ($segment) implements SessionInterface {
            public function __construct(private OsAdminSessionSegment $segment) {}
            public function getPayload(string $payloadClass): object { return $payloadClass === OsAdminSessionSegment::class ? $this->segment : new $payloadClass(); }
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
