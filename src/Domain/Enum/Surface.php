<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Enum;

/**
 * The Observe-stage renderer taxonomy: the set of visual surfaces an intent
 * outcome can request, ordered from "no space" to "most space".
 *
 * Semitexa OS is intent-first / UI-on-demand (concept §1, §8.4): a skill raises
 * a surface ONLY when its intent needs space, and it raises the *smallest* one
 * that satisfies the intent. This enum is that vocabulary; the minimal-sufficient
 * criterion that picks a member lives in {@see \Semitexa\Os\Domain\Model\IntentOutcome::surface()}
 * — the "surface arbiter" the concept calls the design heart of the Observe stage.
 *
 * Gates (ask / refuse / confirm / error) are NOT Observe surfaces — the shell
 * renders those as decision gates — so they resolve to {@see self::None}.
 */
enum Surface: string
{
    /** Headless — nothing to render (a gate handled it, or there is no output). */
    case None = 'none';

    /** Ephemeral toast — the outcome fits in a glance and needs no lasting space. */
    case Cue = 'cue';

    /** Small inline card — a sentence or a single short line. */
    case Inline = 'inline';

    /** Structured key/value rows — a compact tabular readout. */
    case Readout = 'readout';

    /** Full window — the content genuinely needs space (multi-line / large output). */
    case Window = 'window';
}
