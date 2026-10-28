<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Auth\Context\AuthContextStore;

/**
 * Whose calendar the assistant's calendar skills read and write: the person
 * of the running request, as the Calendar app's own routes take it
 * (platform-ui CalendarOwner). The skills used the shared calendar (no user),
 * which the app never shows a signed-in person, so an event made in chat did
 * not appear in Calendar. Null without anyone (a command): the shared one.
 */
final class OsCalendarOwner
{
    public static function current(): ?string
    {
        $id = AuthContextStore::getUser()?->getId();

        return is_string($id) && $id !== '' ? $id : null;
    }
}
