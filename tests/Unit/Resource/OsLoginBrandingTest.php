<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Os\Application\Resource\Response\OsLoginResource;

/**
 * The sign-in page is the screen a person meets BEFORE they are anyone here.
 *
 * It used to head itself with the chat assistant's nickname, so a default
 * install greeted anonymous visitors with an unexplained first name — and
 * renaming your assistant rebranded the login page along with it. The two
 * identities are unrelated; this pins that the page keeps its own.
 */
final class OsLoginBrandingTest extends TestCase
{
    #[Test]
    public function the_page_carries_no_branding_of_its_own_by_default(): void
    {
        $context = (new OsLoginResource())->getRenderContext();

        self::assertArrayNotHasKey(
            'brandTitle',
            $context,
            'Unset is what lets the template fall back to the product wordmark.',
        );
    }

    #[Test]
    public function a_host_with_a_real_name_can_still_supply_one(): void
    {
        $context = (new OsLoginResource())->withBranding('Apart Space')->getRenderContext();

        self::assertSame('Apart Space', $context['brandTitle']);
    }
}
