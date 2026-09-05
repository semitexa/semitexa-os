<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Os\Application\Db\MySQL\Model\ProcessResource;
use Semitexa\Os\Domain\Model\Process;

/** The bridge between the MySQL row and the process the OS shows and reports on. */
#[AsMapper(resourceModel: ProcessResource::class, domainModel: Process::class)]
final class ProcessMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ProcessResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new Process(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            source: $resourceModel->source,
            origin: $resourceModel->origin,
            title: $resourceModel->title,
            status: $resourceModel->status,
            startedAt: $resourceModel->started_at,
            updatedAt: $resourceModel->updated_at,
            progress: $resourceModel->progress,
            detail: $resourceModel->detail,
            completedAt: $resourceModel->completed_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof Process || throw new \InvalidArgumentException('Unexpected domain model.');

        $now = new \DateTimeImmutable();

        return new ProcessResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            source: $domainModel->getSource(),
            origin: $domainModel->getOrigin(),
            title: $domainModel->getTitle(),
            status: $domainModel->getStatus(),
            started_at: $domainModel->getStartedAt() ?? $now,
            updated_at: $domainModel->getUpdatedAt() ?? $now,
            progress: $domainModel->getProgress(),
            detail: $domainModel->getDetail(),
            completed_at: $domainModel->getCompletedAt(),
        );
    }
}
