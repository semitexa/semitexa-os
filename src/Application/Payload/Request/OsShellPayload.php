<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Os\Application\Resource\Response\OsShellResource;

/**
 * The Observe surface (v0): the intent-first web shell. A single floating input
 * summons the Skill loop; results render in place. No desktop, no app icons.
 *
 * Mount path is env-configurable: SEMITEXA_OS_SHELL_PATH=/ serves the shell
 * from the domain root (dev convenience); absent, it stays at /os.
 */
#[AsPublicPayload(
    path: 'env::SEMITEXA_OS_SHELL_PATH::/os',
    methods: ['GET'],
    responseWith: OsShellResource::class,
)]
final class OsShellPayload {}
