<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Os\Application\Resource\Response\OsShellResource;

/**
 * The Observe surface (v0): the intent-first web shell. A single floating input
 * summons the Skill loop; results render in place. No desktop, no app icons.
 */
#[AsPublicPayload(
    path: '/os',
    methods: ['GET'],
    responseWith: OsShellResource::class,
)]
final class OsShellPayload {}
