<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Application\Payload\Request\WeaveProjectsPayload;
use Semitexa\Os\Application\Service\OsGraph;

/**
 * The Files app's project-first start screen: Project nodes from the Weave
 * with their resolved root folders ({@see OsGraph::projects()}). `user_files`
 * is the designated user-files home on the machine that runs the bridge (a
 * HOST path — the PHP app only passes it through so the client can suggest
 * the conventional spot, e.g. $SEMITEXA_USER_FILES/Projects/<name>, for a
 * project that has no folder yet).
 */
#[AsPayloadHandler(payload: WeaveProjectsPayload::class, resource: ResourceResponse::class)]
final class WeaveProjectsHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected OsGraph $graph;

    #[Config(env: 'SEMITEXA_USER_FILES', default: '')]
    protected string $userFiles;

    public function handle(WeaveProjectsPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $body = [
            'projects' => $this->graph->projects(),
            'user_files' => $this->userFiles,
        ];

        return $resource
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->setHeader('Content-Type', 'application/json');
    }
}
