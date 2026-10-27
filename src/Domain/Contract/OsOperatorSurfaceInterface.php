<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Contract;

/**
 * Marks a console surface that shows or acts on the whole install rather than
 * one site: the terminal, the update journal, the queues.
 *
 * {@see OsContentSurfaceInterface} keeps an editor to content. This keeps a
 * site's admin to the site: an admin of one site opened the terminal and ran
 * the install's console skills, read the installed versions and the update
 * runs, and was refused by the queues only because that handler checked for
 * itself. Only an owner opens these; an admin of a single-site install who
 * needs them is made an owner (`user:add --role=owner`).
 *
 * With sign-in off (a personal desktop) the person at the machine is the
 * owner, and the gate lets them through as before.
 */
interface OsOperatorSurfaceInterface extends OsSurfacePayloadInterface
{
}
