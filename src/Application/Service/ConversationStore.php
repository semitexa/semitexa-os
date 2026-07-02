<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Os\Application\Db\MySQL\Model\ConversationTurnResource;

/**
 * The full dialog transcript — every turn of the conversation between the user
 * and the OS, both sides, in order, persisted in the database
 * (`os_conversation_turn`).
 *
 * Distinct from {@see OsSessionStore}, which keeps a small ring buffer of
 * *meaningful outcomes* for the Awakening State Snapshot and the suggestion
 * engine. This store keeps the whole conversation — user intents AND assistant
 * replies, across every decision type (answer / ask / refuse / confirm /
 * executed / open-dialog / error) — so nothing said is lost.
 *
 * Turns are keyed by a UUIDv7 whose millisecond timestamp prefix makes id order
 * chronological, so the transcript reads back in sequence.
 *
 * @phpstan-type Turn array{at: string, role: string, text: string, meta: array<string, mixed>}
 */
#[AsService]
final class ConversationStore
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    /**
     * Append one turn. Empty text is ignored. Best-effort — persistence must
     * never break the loop.
     *
     * @param array<string, mixed> $meta
     */
    public function append(string $role, string $text, array $meta = []): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        try {
            $this->repository()->insert(new ConversationTurnResource(
                id: Uuid7::generate(),
                role: $role === self::ROLE_USER ? self::ROLE_USER : self::ROLE_ASSISTANT,
                text: $text,
                meta_json: (string) json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                created_at: new \DateTimeImmutable(),
            ));
        } catch (\Throwable) {
            // best-effort persistence
        }
    }

    /**
     * The transcript, oldest → newest. Pass a limit to get only the most recent
     * turns (0 = all), still returned in chronological order.
     *
     * @return list<Turn>
     */
    public function turns(int $limit = 0): array
    {
        $query = $this->repository()->query();

        if ($limit > 0) {
            // Most-recent N, then flip back to chronological.
            $rows = $query
                ->orderBy(ConversationTurnResource::column('id'), Direction::Desc)
                ->limit($limit)
                ->fetchAllAs(ConversationTurnResource::class, $this->orm()->getMapperRegistry());
            $rows = array_reverse($rows);
        } else {
            $rows = $query
                ->orderBy(ConversationTurnResource::column('id'), Direction::Asc)
                ->fetchAllAs(ConversationTurnResource::class, $this->orm()->getMapperRegistry());
        }

        return array_map(
            static function (ConversationTurnResource $row): array {
                $meta = json_decode($row->meta_json, true);

                return [
                    'at' => $row->created_at->format('c'),
                    'role' => $row->role,
                    'text' => $row->text,
                    'meta' => is_array($meta) ? $meta : [],
                ];
            },
            $rows,
        );
    }

    public function count(): int
    {
        return $this->repository()->query()->count();
    }

    /**
     * The id of the newest turn (UUIDv7, so lexicographically latest = newest),
     * or '' if there are none. Used to seed the proactive-poll cursor so old
     * messages are never replayed as toasts.
     */
    public function latestId(): string
    {
        /** @var list<ConversationTurnResource> $rows */
        $rows = $this->repository()->query()
            ->orderBy(ConversationTurnResource::column('id'), Direction::Desc)
            ->limit(1)
            ->fetchAllAs(ConversationTurnResource::class, $this->orm()->getMapperRegistry());

        return $rows[0]->id ?? '';
    }

    /**
     * Proactive assistant turns (meta.proactive === true) created AFTER $afterId,
     * oldest → newest. This is how the assistant "speaks first": a background job
     * appends a proactive turn, the shell polls /os/proactive and surfaces it.
     *
     * @return list<array{id: string, text: string, meta: array<string, mixed>}>
     */
    public function proactiveAfter(string $afterId, int $scan = 40): array
    {
        /** @var list<ConversationTurnResource> $rows */
        $rows = $this->repository()->query()
            ->orderBy(ConversationTurnResource::column('id'), Direction::Desc)
            ->limit($scan)
            ->fetchAllAs(ConversationTurnResource::class, $this->orm()->getMapperRegistry());

        $out = [];
        foreach (array_reverse($rows) as $row) {
            if ($afterId !== '' && strcmp($row->id, $afterId) <= 0) {
                continue;
            }
            $meta = json_decode($row->meta_json, true);
            if (!is_array($meta) || ($meta['proactive'] ?? false) !== true) {
                continue;
            }
            $out[] = ['id' => $row->id, 'text' => $row->text, 'meta' => $meta];
        }

        return $out;
    }

    /** Start a fresh conversation — remove every stored turn. */
    public function clear(): void
    {
        /** @var list<ConversationTurnResource> $rows */
        $rows = $this->repository()->query()
            ->fetchAllAs(ConversationTurnResource::class, $this->orm()->getMapperRegistry());
        foreach ($rows as $row) {
            $this->repository()->delete($row);
        }
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            ConversationTurnResource::class,
            ConversationTurnResource::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
