<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Os\Application\Db\MySQL\Model\ConversationSummaryResource;

/**
 * Self-mapping mapper for {@see ConversationSummaryResource} — resource is the
 * domain model, both directions are clone-passthroughs.
 */
#[AsMapper(
    resourceModel: ConversationSummaryResource::class,
    domainModel: ConversationSummaryResource::class,
)]
final class ConversationSummaryMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ConversationSummaryResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ConversationSummaryResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}
