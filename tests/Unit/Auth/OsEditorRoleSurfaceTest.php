<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Llm\Application\Service\SkillRegistry;
use Semitexa\Llm\Application\Service\TenantSkillScope;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Os\Domain\Enum\IntentDecision;
use Semitexa\Llm\Domain\Model\ScopedSkillManifest;
use Semitexa\Llm\Domain\Model\SkillEntry;
use Semitexa\Llm\Domain\Model\SkillManifest;
use Semitexa\Llm\Domain\Model\SkillScope;
use Semitexa\Os\Application\Payload\Request\WeaveAttachPayload;
use Semitexa\Os\Application\Payload\Request\WeaveGraphPayload;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Application\Service\OsConsoleAccess;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Os\Application\Service\OsStatusSkill;
use Semitexa\Os\Application\Service\SkillLoopRunner;
use Semitexa\Os\Application\Service\WeaveAttachSkill;
use Semitexa\Os\Application\Service\WeaveShowSkill;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Os\Pipeline\OsAdminGate;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Model\PlatformUser;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * What a role opens.
 *
 * `user:add` offered `--role editor` and created the account; the login handler
 * then rejected it with the generic "Wrong email or password", so an operator
 * got an account that could never sign in and a failure that looked like a typo.
 *
 * Admitting the role is only half of it. The dangerous half is admitting it to
 * everything: the console's marker covers the terminal, the assistant's prompts,
 * the process registry and the update surface as readily as it covers a page.
 * These pin both directions, because a test that only proves the editor gets IN
 * would pass just as well on the regression.
 */
final class OsEditorRoleSurfaceTest extends TestCase
{
    use BuildsContainerManagedObjects;

    protected function tearDown(): void
    {
        AuthContextStore::clear();
    }

    private function gate(): OsAdminGate
    {
        // OsAuthPolicy is final, so the real one is built and told to require
        // auth through the flag it already reads.
        $policy = $this->createWithDependencies(OsAuthPolicy::class, ['authFlag' => '1']);
        self::assertTrue($policy->isRequired(), 'the gate is inert unless auth is required');

        return $this->createWithDependencies(OsAdminGate::class, ['policy' => $policy]);
    }

    private function contextFor(UserRole $role, object $payload): RequestPipelineContext
    {
        // Built through the real constructor rather than reflected into shape:
        // a half-initialised model breaks the moment the class gains a typed
        // property, which is exactly what happened when it gained one.
        $user = new PlatformUser(
            id: 'u1',
            email: 'editor@example.com',
            passwordHash: 'x',
            role: $role,
        );

        // Its own constructor, not reflection: UserPrincipal is a value object,
        // and one assembled by reflection silently keeps working when a required
        // property is added — which is how two PlatformUser tests failed on a
        // change that was correct.
        $principal = new UserPrincipal($user);

        $context = (new \ReflectionClass(RequestPipelineContext::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RequestPipelineContext::class, 'requestDto'))->setValue($context, $payload);
        (new \ReflectionProperty(RequestPipelineContext::class, 'authResult'))
            ->setValue($context, new AuthResult(true, $principal));

        return $context;
    }

    private function contentSurface(): object
    {
        return new class implements OsContentSurfaceInterface {};
    }

    private function consoleSurface(): object
    {
        return new class implements OsSurfacePayloadInterface {};
    }

    private function assertAdmitted(UserRole $role, object $payload, string $what): void
    {
        try {
            $this->gate()->handle($this->contextFor($role, $payload));
        } catch (AccessDeniedException $e) {
            $this->fail("{$role->value} was refused {$what}: {$e->getMessage()}");
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_editor_reaches_a_content_surface(): void
    {
        $this->assertAdmitted(UserRole::Editor, $this->contentSurface(), 'a content surface');
    }

    #[Test]
    public function an_editor_is_refused_a_console_surface_beyond_content(): void
    {
        // The terminal, the prompts, the process registry, the updates surface.
        $this->expectException(AccessDeniedException::class);
        $this->gate()->handle($this->contextFor(UserRole::Editor, $this->consoleSurface()));
    }

    #[Test]
    public function an_admin_opens_both(): void
    {
        $this->assertAdmitted(UserRole::Admin, $this->contentSurface(), 'a content surface');
        $this->assertAdmitted(UserRole::Admin, $this->consoleSurface(), 'a console surface');
    }

    #[Test]
    public function an_owner_opens_both(): void
    {
        $this->assertAdmitted(UserRole::Owner, $this->contentSurface(), 'a content surface');
        $this->assertAdmitted(UserRole::Owner, $this->consoleSurface(), 'a console surface');
    }

    /**
     * The classification itself, on the real payloads.
     *
     * The gate tests above would pass on any classification, including one that
     * marked the terminal as content. These are the ones that would not.
     */
    #[Test]
    public function the_operator_toolkit_is_not_content(): void
    {
        foreach ([
            \Semitexa\Os\Application\Payload\Request\TerminalAppPayload::class,
            \Semitexa\Os\Application\Payload\Request\PromptsAppPayload::class,
            \Semitexa\Os\Application\Payload\Request\PromptsSavePayload::class,
            \Semitexa\Os\Application\Payload\Request\PromptsHistoryPayload::class,
            \Semitexa\Os\Application\Payload\Request\ProcessListPayload::class,
            \Semitexa\Os\Application\Payload\Request\ProcessFeedPayload::class,
            \Semitexa\Os\Application\Payload\Request\ProcessReportPayload::class,
            \Semitexa\Os\Application\Payload\Request\UpdatesAppPayload::class,
            \Semitexa\Os\Application\Payload\Request\WeaveGraphPayload::class,
            \Semitexa\Os\Application\Payload\Request\WeaveNodeSavePayload::class,
        ] as $class) {
            $this->assertTrue(
                is_a($class, OsSurfacePayloadInterface::class, true),
                $class . ' is not a console surface at all',
            );
            $this->assertFalse(
                is_a($class, OsContentSurfaceInterface::class, true),
                $class . ' would be handed to a client\'s content editor',
            );
        }
    }

    #[Test]
    public function the_editors_own_work_is_content(): void
    {
        foreach ([
            \Semitexa\Cms\Application\Payload\Request\ContentEditorPayload::class,
            \Semitexa\Cms\Application\Payload\Request\ContentSavePayload::class,
            \Semitexa\Os\Application\Payload\Request\ConversationPayload::class,
            \Semitexa\Os\Application\Payload\Request\IntentPayload::class,
            \Semitexa\Os\Application\Payload\Request\OpenDialogPayload::class,
            \Semitexa\Os\Application\Payload\Request\PreferencesUpdatePayload::class,
            // Both legs of a confirmation, or the chat dead-ends: an editor
            // could approve a single proposed skill and not a chain of them,
            // and the OS proposes chains on its own.
            \Semitexa\Os\Application\Payload\Request\ApproveSkillPayload::class,
            \Semitexa\Os\Application\Payload\Request\ApprovePipelinePayload::class,
        ] as $class) {
            $this->assertTrue(
                is_a($class, OsContentSurfaceInterface::class, true),
                $class . ' is out of reach for the person the role exists for',
            );
        }
    }

    /**
     * The chat is a content surface, and it ran what the windows refuse: an
     * editor asked to see something "in my world" and the skill ran, then the
     * Workspace it ends in answered 403.
     */
    #[Test]
    public function an_editor_s_chat_loses_the_skills_that_end_in_a_surface_they_are_refused(): void
    {
        self::assertSame(WeaveGraphPayload::class, WeaveShowSkill::surface());
        self::assertSame(WeaveAttachPayload::class, WeaveAttachSkill::surface());

        $manifest = $this->siteManifest();
        $offered = self::names($manifest);
        foreach (['show-in-world', 'attach-folder', 'recall', 'remember', 'create-task'] as $skill) {
            self::assertContains($skill, $offered, $skill . ' is not in a site\'s manifest, so nothing below proves anything');
        }

        $this->signIn(UserRole::Editor);
        $left = self::names($this->access()->narrow($manifest));

        self::assertNotContains('show-in-world', $left);
        self::assertNotContains('attach-folder', $left);
        // The console is one shared workspace: memory and tasks are common to
        // everyone admitted, so a skill that ends in the chat stays.
        foreach (['recall', 'remember', 'create-task'] as $skill) {
            self::assertContains($skill, $left, $skill);
        }
        self::assertSame(array_values(array_diff($offered, ['show-in-world', 'attach-folder'])), $left, 'nothing else goes');
    }

    #[Test]
    public function an_admin_s_chat_keeps_every_skill(): void
    {
        $manifest = $this->siteManifest();

        $this->signIn(UserRole::Admin);

        self::assertSame(self::names($manifest), self::names($this->access()->narrow($manifest)));
    }

    /**
     * POST /os/approve-skill and /os/pipeline name a skill themselves: no
     * planner stands between an editor and one it was never offered.
     */
    #[Test]
    public function an_editor_s_direct_approve_of_such_a_skill_is_refused(): void
    {
        $runner = $this->createWithDependencies(SkillLoopRunner::class, [
            'scopes' => new TenantSkillScope(),
            'access' => $this->access(),
        ]);
        $this->signIn(UserRole::Editor);

        $approved = $runner->approveAndExecute('show Anna', 'show-in-world', ['query' => 'Anna'], SkillScope::forTenant('museum'));
        self::assertSame(IntentDecision::Error, $approved->decision);
        self::assertSame("Unknown skill 'show-in-world' — rejected.", $approved->error);

        $chained = $runner->executePipeline('attach it', [['skill' => 'attach-folder', 'arguments' => ['folder' => 'x']]], SkillScope::forTenant('museum'), false);
        self::assertSame(IntentDecision::Error, $chained->decision);
        self::assertSame("Unknown skill 'attach-folder' in pipeline — rejected.", $chained->error);
    }

    /** os:status counted the whole install's registry: other sites' skills and the operator's. */
    #[Test]
    public function the_status_skill_counts_the_caller_s_own_skills(): void
    {
        $policy = $this->createWithDependencies(OsAuthPolicy::class, ['authFlag' => '1']);
        // A skill is no service: the runner builds it and fills these.
        $status = new OsStatusSkill();
        foreach ([
            'scope' => $this->createWithDependencies(OsSkillScope::class, ['policy' => $policy]),
            'scopes' => new TenantSkillScope(),
            'access' => $this->access(),
        ] as $property => $service) {
            (new \ReflectionProperty($status, $property))->setValue($status, $service);
        }
        $own = (new TenantSkillScope())->manifestFor(SkillScope::forTenant('museum'), SkillLoopRunner::OS_CHANNELS);
        $install = count((new SkillRegistry())->buildManifest()->skills);
        self::assertGreaterThan(count($own->skills()), $install, 'the install has skills a site does not, or the counts cannot differ');

        $this->signIn(UserRole::Admin);
        self::assertSame(count($own->skills()), self::registered($status->invoke([])));

        $this->signIn(UserRole::Editor);
        self::assertSame(count($own->skills()) - 2, self::registered($status->invoke([])), 'less the two skills an editor is refused');

        AuthContextStore::clear();
        self::assertSame(0, self::registered($status->invoke([])), 'nobody signed in');
    }

    private static function registered(string $report): int
    {
        self::assertSame(1, preg_match('/^Skills registered : (\d+)$/m', $report, $m), $report);

        return (int) $m[1];
    }

    private function access(): OsConsoleAccess
    {
        return $this->createWithDependencies(OsConsoleAccess::class, [
            'policy' => $this->createWithDependencies(OsAuthPolicy::class, ['authFlag' => '1']),
        ]);
    }

    private function signIn(UserRole $role): void
    {
        AuthContextStore::setUser(new UserPrincipal(new PlatformUser(
            id: 'u1',
            email: 'editor@example.com',
            passwordHash: 'x',
            tenantId: 'museum',
            role: $role,
        )));
    }

    /**
     * What a site's person is offered in the chat: the real manifest of the
     * packages installed, and create-task, which semitexa/tasks ships and
     * this package does not require: a module's skill that declares nothing.
     */
    private function siteManifest(): ScopedSkillManifest
    {
        $skills = (new TenantSkillScope())->manifestFor(SkillScope::forTenant('museum'), SkillLoopRunner::OS_CHANNELS)->skills();

        if (!in_array('create-task', array_map(static fn (SkillEntry $s): string => $s->name, $skills), true)) {
            $skills[] = new SkillEntry(
                name: 'create-task',
                sourceCommand: null,
                summary: 'Create a task.',
                useWhen: '',
                avoidWhen: '',
                riskLevel: AiRiskLevel::Low,
                confirmation: AiConfirmationMode::Never,
                supportsDryRun: false,
                argumentPolicy: AiArgumentPolicy::None,
                inputs: [],
                channels: ['web'],
                executionKind: AiExecutionKind::DirectCommand,
                skillClass: (new class {})::class,
            );
        }

        return (new SkillManifest('semitexa.ai-skills/v1', 'now', $skills))->forChannels(SkillLoopRunner::OS_CHANNELS);
    }

    /** @return list<string> */
    private static function names(ScopedSkillManifest $manifest): array
    {
        return array_map(static fn (SkillEntry $s): string => $s->name, $manifest->skills());
    }
}
