<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Enum;

/**
 * The outcome class of a single intent passed through the OS Skill loop.
 *
 * Maps the planner's typed decision ({@see \Semitexa\Llm\Domain\Enum\PlannerResponseType})
 * plus execution/policy state onto the vocabulary the Observe surface renders.
 */
enum IntentDecision: string
{
    /** Planner answered directly; no skill involved. */
    case Answer = 'answer';

    /** Planner needs more information before it can act. */
    case Ask = 'ask';

    /** Planner declined / refused the intent. */
    case Refuse = 'refuse';

    /** A skill was proposed but its confirmation policy requires explicit approval before running. */
    case NeedsConfirmation = 'needs_confirmation';

    /** A skill was executed; see exitCode/output. */
    case Executed = 'executed';

    /** A UI-skill was opened as a dialog window; the shell switches to Focus. */
    case OpenDialog = 'open_dialog';

    /**
     * A UI-skill was proposed, but a dialog for it is already running. The OS
     * asks rather than silently duplicating it: open another, or switch to the
     * open one.
     */
    case DialogExists = 'dialog_exists';

    /** The loop could not run (e.g. LLM provider unreachable, unknown skill proposed). */
    case Error = 'error';
}
