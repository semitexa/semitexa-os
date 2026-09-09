<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Os\Pipeline\OsAdminGate;
use Semitexa\Platform\User\Auth\UserPrincipal;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Model\PlatformUser;

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
    private function gate(): OsAdminGate
    {
        $gate = (new \ReflectionClass(OsAdminGate::class))->newInstanceWithoutConstructor();

        // OsAuthPolicy is final, so the real one is built and told to require
        // auth through the flag it already reads.
        $policy = (new \ReflectionClass(OsAuthPolicy::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(OsAuthPolicy::class, 'authFlag'))->setValue($policy, '1');
        (new \ReflectionProperty(OsAdminGate::class, 'policy'))->setValue($gate, $policy);
        self::assertTrue($policy->isRequired(), 'the gate is inert unless auth is required');

        return $gate;
    }

    private function contextFor(UserRole $role, object $payload): RequestPipelineContext
    {
        $user = (new \ReflectionClass(PlatformUser::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(PlatformUser::class, 'role'))->setValue($user, $role);

        $principal = (new \ReflectionClass(UserPrincipal::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(UserPrincipal::class, 'user'))->setValue($principal, $user);

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

    private function operatorSurface(): object
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
    public function an_editor_is_refused_an_operator_surface(): void
    {
        // The terminal, the prompts, the process registry, the updates surface.
        $this->expectException(AccessDeniedException::class);
        $this->gate()->handle($this->contextFor(UserRole::Editor, $this->operatorSurface()));
    }

    #[Test]
    public function an_admin_opens_both(): void
    {
        $this->assertAdmitted(UserRole::Admin, $this->contentSurface(), 'a content surface');
        $this->assertAdmitted(UserRole::Admin, $this->operatorSurface(), 'an operator surface');
    }

    #[Test]
    public function an_owner_opens_both(): void
    {
        $this->assertAdmitted(UserRole::Owner, $this->contentSurface(), 'a content surface');
        $this->assertAdmitted(UserRole::Owner, $this->operatorSurface(), 'an operator surface');
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
}
