<?php

declare(strict_types=1);

namespace Semitexa\Os;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * The package ships no attributes of its own, so there is nothing for a
 * mechanism-level declaration to hang on — and without this the package is
 * invisible to anyone whose project has not installed it, which is precisely
 * the audience worth telling. The convention is one `Capabilities` class per
 * package: a definite place to look, and a definite place for a guard to check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'os.shell',
    summary: 'An intent-first environment shell running the Intent to Plan to Execute to Observe loop over Skills, generalised beyond the terminal.',
    useWhen: 'The product is an environment where users state what they want and the system plans and carries it out, rather than clicking through fixed navigation.',
    avoidWhen: 'The product is a conventional web application with a known set of screens. A shell adds a planning loop nobody asked for.',
    replaces: [
        'a bespoke dashboard wiring every action to its own route and button',
        'a command palette that maps typed text to a hard-coded list of actions',
    ],
)]
final class Capabilities
{
}
