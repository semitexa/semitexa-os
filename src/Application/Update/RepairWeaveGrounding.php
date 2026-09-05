<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Update;

use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Repair the grounding edges the OS wrote against its own owner node.
 *
 * Three writers — the weaver's orphan grounding, the remember skill and the
 * filesystem attach — recorded "owner PART_OF thing". Both halves were wrong:
 * PART_OF asserts containment where only membership of the person's world was
 * known, and its own contract puts the child first, so the owner was recorded
 * as the part. The result read back to the assistant as "you part_of Олена"
 * and "you part_of гітара".
 *
 * This flips those edges to "thing RELATED_TO owner" — the weakest, inferred
 * edge, which is all that was ever known.
 *
 * Scope is deliberately narrow: only edges whose subject is a node flagged
 * is_self. Edges the model actually asserted are never touched, and a genuine
 * containment between two things ("folder part_of project") is left alone.
 *
 * Idempotent: after the flip no such row matches. Skips a row whose flipped
 * form already exists, since (from_id, to_id, relation) is unique.
 */
#[AsDataPatch(
    id: 'repair-weave-self-grounding',
    module: 'semitexa/os',
    phase: UpdatePhase::Post,
    requiresColumns: ['weave_edge' => ['from_id', 'to_id', 'relation'], 'weave_node' => ['properties_json']],
    description: 'Rewrite "owner part_of thing" grounding edges as "thing related_to owner".',
)]
final class RepairWeaveGrounding implements DataPatchInterface
{
    public function apply(DataPatchContext $ctx): void
    {
        // Drop any row whose repaired form is already present — the unique
        // triple index would reject the update, and the survivor says the same.
        $ctx->execute(
            <<<'SQL'
            DELETE e FROM `weave_edge` e
            JOIN `weave_node` n ON n.`id` = e.`from_id`
            WHERE e.`relation` = 'part_of'
              AND JSON_EXTRACT(n.`properties_json`, '$.is_self') = TRUE
              AND EXISTS (
                  SELECT 1 FROM (SELECT * FROM `weave_edge`) x
                  WHERE x.`from_id` = e.`to_id` AND x.`to_id` = e.`from_id` AND x.`relation` = 'related_to'
              )
            SQL,
        );

        // n.id is the ORIGINAL subject: joined, so it is unaffected by the
        // left-to-right evaluation of the assignments below.
        $ctx->execute(
            <<<'SQL'
            UPDATE `weave_edge` e
            JOIN `weave_node` n ON n.`id` = e.`from_id`
            SET e.`from_id` = e.`to_id`,
                e.`to_id` = n.`id`,
                e.`relation` = 'related_to'
            WHERE e.`relation` = 'part_of'
              AND JSON_EXTRACT(n.`properties_json`, '$.is_self') = TRUE
            SQL,
        );
    }
}
