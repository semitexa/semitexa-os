<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Os\Application\Db\MySQL\Model\ConversationTurnResource;
use Semitexa\Os\Domain\Model\ConversationTurn;

/**
 * The bridge between the MySQL row and one turn of the conversation. The meta
 * is a JSON string in the column and an array in the turn — the encoding is the
 * table's, decoded once here rather than at every read site.
 */
#[AsMapper(resourceModel: ConversationTurnResource::class, domainModel: ConversationTurn::class)]
final class ConversationTurnMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ConversationTurnResource
            || throw new \InvalidArgumentException('Unexpected resource model.');

        $meta = json_decode($resourceModel->meta_json, true);

        return new ConversationTurn(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            role: $resourceModel->role,
            text: $resourceModel->text,
            // A malformed row must not break a transcript read.
            meta: is_array($meta) ? $meta : [],
            createdAt: $resourceModel->created_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ConversationTurn || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ConversationTurnResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            role: $domainModel->getRole(),
            text: $domainModel->getText(),
            meta_json: (string) json_encode($domainModel->getMeta(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            created_at: $domainModel->getCreatedAt() ?? new \DateTimeImmutable(),
        );
    }
}
