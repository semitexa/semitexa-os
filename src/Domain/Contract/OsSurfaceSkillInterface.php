<?php

declare(strict_types=1);

namespace Semitexa\Os\Domain\Contract;

/**
 * A chat skill that ends in a console surface: what it says is only the
 * first half, and the shell then calls that surface to finish the job
 * (show-in-world opens the Workspace on the graph, attach-folder posts the
 * folder to it).
 *
 * The skill names the surface, and the console leaves the skill out for a
 * person whose role the gate would refuse there
 * ({@see \Semitexa\Os\Application\Service\OsConsoleAccess::narrow()}): an
 * editor was offered both, and the request that followed answered 403.
 *
 * A skill that ends in the chat itself declares nothing and stays for
 * everyone admitted to the console: its chat and memory are shared.
 */
interface OsSurfaceSkillInterface
{
    /** @return class-string<OsSurfacePayloadInterface> */
    public static function surface(): string;
}
