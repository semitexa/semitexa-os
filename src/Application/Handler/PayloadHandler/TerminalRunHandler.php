<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Llm\Application\Service\TenantSkillScope;
use Semitexa\Os\Application\Payload\Request\TerminalRunPayload;
use Semitexa\Os\Application\Service\OsSkillScope;
use Semitexa\Os\Application\Service\SkillLoopRunner;

/**
 * Runs a console skill the Terminal lists: the same manifest it was listed
 * from (this scope, the console channel), no arguments, as the Terminal types
 * a name, on the runner's DI-backed executor (SkillLoopRunner::executor()).
 * The route is the owner's (OsOperatorSurfaceInterface).
 */
#[AsPayloadHandler(payload: TerminalRunPayload::class, resource: ResourceResponse::class)]
final class TerminalRunHandler implements TypedHandlerInterface
{
    private const CHANNEL = 'console';

    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected OsSkillScope $skillScope;

    #[InjectAsReadonly]
    protected TenantSkillScope $skills;

    #[InjectAsReadonly]
    protected SkillLoopRunner $runner;

    public function handle(TerminalRunPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $scope = $this->skillScope->forSession(isset($this->session) ? $this->session : null);
        if ($scope === null) {
            return self::json($resource, 401, ['error' => 'Sign in again to run this.']);
        }

        $manifest = $this->skills->manifestFor($scope, [self::CHANNEL]);
        $name = trim($payload->getSkill());
        $entry = $manifest->findSkill($name);
        // A UI skill raises a window; it has no command to type.
        if ($entry === null || $entry->isUi()) {
            return self::json($resource, 404, ['error' => "Unknown console skill '{$name}'.", 'exit_code' => null]);
        }

        $result = $this->runner->executor()->execute($name, [], $manifest, self::CHANNEL);

        return self::json($resource, 200, [
            'skill' => $result->skill,
            'output' => $result->output,
            'error' => $result->error,
            'exit_code' => $result->exitCode,
        ]);
    }

    /** @param array<string, mixed> $body */
    private static function json(ResourceResponse $resource, int $status, array $body): ResourceResponse
    {
        return $resource
            ->setStatusCode($status)
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
