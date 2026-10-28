<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Update;

use Semitexa\Core\Environment;
use Semitexa\Os\Application\Service\OsAuthPolicy;
use Semitexa\Os\Auth\OsDesktopPrincipal;
use Semitexa\Os\Domain\Enum\WindowMode;
use Semitexa\Update\Attribute\AsDataPatch;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Give the shared calendar to the person at a desktop install.
 *
 * Before 2026-10-27 the assistant's calendar skills wrote events with no user
 * (user_id NULL), and the Calendar app shows a person only their own; on a
 * desktop install with sign-in off the app did not open at all. That person
 * now reads and writes as OsDesktopPrincipal ('os-desktop'), so the events the
 * assistant made for them become theirs.
 *
 * Only on a desktop install with sign-in off, where one person is the only
 * one the shared rows can belong to. Anywhere else nothing tells whose they
 * were, and the patch changes nothing. Idempotent: only rows with no user.
 */
#[AsDataPatch(
    id: 'claim-desktop-calendar',
    module: 'semitexa/os',
    phase: UpdatePhase::Post,
    requiresColumns: ['platform_calendar_events' => ['user_id']],
    description: 'On a desktop install, give calendar events with no owner to the person at the machine.',
)]
final class ClaimDesktopCalendar implements DataPatchInterface
{
    public function apply(DataPatchContext $ctx): void
    {
        $mode = WindowMode::tryFrom(strtolower(trim((string) Environment::getEnvValue('SEMITEXA_WINDOW_MODE', 'web')))) ?? WindowMode::Web;
        if (OsAuthPolicy::requiredFor($mode, (string) Environment::getEnvValue('SEMITEXA_OS_AUTH', ''))) {
            return;
        }

        $ctx->execute('UPDATE `platform_calendar_events` SET `user_id` = :owner WHERE `user_id` IS NULL', ['owner' => (new OsDesktopPrincipal())->getId()]);
    }
}
