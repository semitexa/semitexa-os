<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Contract;

/**
 * Marks a console surface as part of a content editor's job.
 *
 * {@see OsSurfacePayloadInterface} answers "is this the console?". This answers
 * the narrower question the roles actually mean: may somebody whose rights are
 * content rights open it? A client's editor needs the chat, their windows, their
 * own preferences and the CMS. They do not need the terminal, the assistant's
 * prompts, the process registry, the update surface or the file manager — those
 * are the operator's toolkit, and handing them over with the role is how "let the
 * editor in" turns into a privilege regression.
 *
 * A marker rather than a list in the gate, for the same reason the interface
 * above is one: the app packages mount their own windows under `/os/app`, and a
 * rule kept centrally would silently miss them. It is also why this fails
 * CLOSED — a payload that carries nothing is operator-only until someone
 * deliberately says otherwise, so the mistake a new surface can make is to be
 * too private rather than too open.
 *
 * Owner and Admin are unaffected: they open the whole console either way.
 */
interface OsContentSurfaceInterface extends OsSurfacePayloadInterface
{
}
