<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * ORM resource for one turn of the OS dialog transcript.
 *
 * The primary key is a UUIDv7 supplied by the store: v7's 48-bit millisecond
 * timestamp prefix means lexicographic id order IS chronological order, so the
 * transcript reads back in sequence without a separate ordering column.
 *
 * `final readonly` + constructor-promoted columns per the current ORM contract.
 */
#[FromTable(name: 'os_conversation_turn')]
#[Index(columns: ['created_at'], name: 'idx_os_conversation_created')]
final readonly class ConversationTurnResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $role,

        #[Column(type: MySqlType::LongText)]
        public string $text,

        /** JSON-encoded per-turn metadata (decision, skill, surface). */
        #[Column(type: MySqlType::LongText)]
        public string $meta_json,

        #[Column(type: MySqlType::Datetime)]
        public \DateTimeImmutable $created_at,
    ) {}
}
