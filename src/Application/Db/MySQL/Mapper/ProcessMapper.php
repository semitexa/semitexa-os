<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Os\Application\Db\MySQL\Model\ProcessResource;

/**
 * Self-mapping mapper for {@see ProcessResource} — the resource IS the domain
 * model, both directions are clone-passthroughs (mirrors TaskMapper).
 */
#[AsMapper(
    resourceModel: ProcessResource::class,
    domainModel: ProcessResource::class,
)]
final class ProcessMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ProcessResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ProcessResource
            || throw new \InvalidArgumentException('Unexpected domain model.');

        return clone $domainModel;
    }
}
