<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Os\Application\Db\MySQL\Model\ConversationSummaryResource;
use Semitexa\Os\Domain\Model\ConversationSummary;

/**
 * The bridge between the MySQL row and the summary the skill loop reasons
 * about: snake_case columns on one side, the OS's own names on the other, so
 * another database could carry the same conversation memory.
 */
#[AsMapper(resourceModel: ConversationSummaryResource::class, domainModel: ConversationSummary::class)]
final class ConversationSummaryMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ConversationSummaryResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return new ConversationSummary(
            tenantId: $resourceModel->tenant_id,
            summaryText: $resourceModel->summary_text,
            activeIntent: $resourceModel->active_intent,
            coveredThroughId: $resourceModel->covered_through_id,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ConversationSummary
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ConversationSummaryResource(
            tenant_id: $domainModel->getTenantId(),
            summary_text: $domainModel->getSummaryText(),
            active_intent: $domainModel->getActiveIntent(),
            covered_through_id: $domainModel->getCoveredThroughId(),
            updated_at: $domainModel->getUpdatedAt() ?? new \DateTimeImmutable(),
        );
    }
}
