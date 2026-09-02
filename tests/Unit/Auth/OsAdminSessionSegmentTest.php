<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\PayloadSerializer;
use Semitexa\Os\Application\Payload\Session\OsAdminSessionSegment;

/**
 * The segment is only useful if it survives the round trip through the session
 * store. It did not, at first: Session::getPayload() rehydrates through
 * PayloadSerializer::hydrate(), which calls single-argument set{Key}() methods
 * and silently skips everything else — so a segment whose only mutator was
 * signIn($userId, $at) wrote correctly and read back empty. Every sign-in
 * looked like it worked and no session was ever recognised.
 */
final class OsAdminSessionSegmentTest extends TestCase
{
    #[Test]
    public function a_signed_in_segment_survives_the_session_round_trip(): void
    {
        $segment = new OsAdminSessionSegment();
        $segment->signIn('01a061d2-cc11-7957-b931-86ea87139491', 1788348178);

        $restored = PayloadSerializer::hydrate(
            new OsAdminSessionSegment(),
            PayloadSerializer::toArray($segment),
        );

        self::assertInstanceOf(OsAdminSessionSegment::class, $restored);
        self::assertTrue($restored->isSignedIn());
        self::assertSame('01a061d2-cc11-7957-b931-86ea87139491', $restored->getUserId());
        self::assertSame(1788348178, $restored->getSignedInAt());
    }

    #[Test]
    public function the_intended_path_survives_the_round_trip_too(): void
    {
        $segment = new OsAdminSessionSegment();
        $segment->setIntendedPath('/admin');

        $restored = PayloadSerializer::hydrate(
            new OsAdminSessionSegment(),
            PayloadSerializer::toArray($segment),
        );

        self::assertSame('/admin', $restored->getIntendedPath());
    }

    #[Test]
    public function signing_out_leaves_nothing_behind(): void
    {
        $segment = new OsAdminSessionSegment();
        $segment->signIn('u-1');
        $segment->setIntendedPath('/admin');
        $segment->signOut();

        $restored = PayloadSerializer::hydrate(
            new OsAdminSessionSegment(),
            PayloadSerializer::toArray($segment),
        );

        self::assertFalse($restored->isSignedIn());
        self::assertNull($restored->getUserId());
        self::assertNull($restored->getIntendedPath());
    }

    #[Test]
    public function an_empty_user_id_is_never_a_session(): void
    {
        $segment = new OsAdminSessionSegment();
        $segment->setUserId('   ');

        self::assertFalse($segment->isSignedIn());
        $this->expectException(\InvalidArgumentException::class);
        $segment->signIn('  ');
    }
}
