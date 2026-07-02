<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Os\Application\Db\MySQL\Model\ConversationTurnResource;

/**
 * Self-mapping mapper for {@see ConversationTurnResource} — resource is the
 * domain model, both directions are clone-passthroughs.
 */
#[AsMapper(
    resourceModel: ConversationTurnResource::class,
    domainModel: ConversationTurnResource::class,
)]
final class ConversationTurnMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ConversationTurnResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ConversationTurnResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}
