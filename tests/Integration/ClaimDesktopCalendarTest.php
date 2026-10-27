<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Orm\OrmManager;
use Semitexa\Os\Application\Service\CalendarCreateSkill;
use Semitexa\Os\Application\Update\ClaimDesktopCalendar;
use Semitexa\Os\Auth\OsDesktopPrincipal;
use Semitexa\PlatformUi\Domain\Contract\CalendarEventRepositoryInterface;
use Semitexa\PlatformUi\Domain\Model\CalendarEvent;
use Semitexa\Update\Context\DataPatchContext;

/**
 * The assistant's calendar events had no owner, and the Calendar app shows a
 * person only their own. On the orm:sync'ed MySQL table, inside a transaction
 * rolled back at the end: the patch changes every unowned row.
 */
final class ClaimDesktopCalendarTest extends TestCase
{
    private OrmManager $orm;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->orm = new OrmManager();
        self::assertSame('mysql', $this->orm->getDriver(), 'bin/semitexa test:run runs on MySQL');
        foreach (['SEMITEXA_WINDOW_MODE', 'SEMITEXA_OS_AUTH'] as $name) {
            $this->env[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        AuthContextStore::clear();
        $this->orm->shutdown();
    }

    #[Test]
    public function on_a_desktop_install_the_person_at_the_machine_gets_the_unowned_events(): void
    {
        self::assertSame(['unowned' => 'os-desktop', 'someone\'s' => 'someone'], $this->patchedOwners('os', ''));
    }

    #[Test]
    public function anywhere_else_nothing_changes(): void
    {
        self::assertSame(['unowned' => null, 'someone\'s' => 'someone'], $this->patchedOwners('web', '0'), 'a web install cannot switch sign-in off');
        self::assertSame(['unowned' => null, 'someone\'s' => 'someone'], $this->patchedOwners('os', '1'), 'a desktop install that asks for a sign-in');
    }

    /** It was built with `new`: no tenant context, every site's events in the default site's calendar. */
    #[Test]
    public function the_assistant_writes_through_the_container_s_repository_as_the_person(): void
    {
        $events = new class implements CalendarEventRepositoryInterface {
            public ?CalendarEvent $inserted = null;
            public function findInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $userId = null): array { return []; }
            public function findById(string $id): ?CalendarEvent { return null; }
            public function insert(CalendarEvent $event): void { $this->inserted = $event; }
            public function update(CalendarEvent $event): void {}
            public function deleteById(string $id): void {}
        };
        $skill = new CalendarCreateSkill();
        (new \ReflectionProperty($skill, 'events'))->setValue($skill, $events);
        AuthContextStore::setUser(new OsDesktopPrincipal());

        $skill->invoke(['title' => 'Dentist', 'when' => 'tomorrow 10:00']);

        self::assertSame('os-desktop', $events->inserted?->getUserId());
    }

    /** @return array<string, ?string> */
    private function patchedOwners(string $mode, string $flag): array
    {
        putenv("SEMITEXA_WINDOW_MODE={$mode}");
        putenv("SEMITEXA_OS_AUTH={$flag}");
        $owners = [];
        try {
            $this->orm->getTransactionManager()->run(function () use (&$owners): void {
                $now = gmdate('Y-m-d H:i:s');
                foreach (['unowned' => null, 'someone\'s' => 'someone'] as $title => $user) {
                    $this->orm->getQueryAdapter()->execute(
                        'INSERT INTO platform_calendar_events (id, tenant_id, user_id, title, starts_at, ends_at, all_day, created_at, updated_at) VALUES (:id, :t, :u, :title, :s, :e, 0, :c, :m)',
                        ['id' => 'claim-test-' . bin2hex(random_bytes(8)), 't' => 'default', 'u' => $user, 'title' => 'claim-test ' . $title, 's' => $now, 'e' => $now, 'c' => $now, 'm' => $now],
                    );
                }
                (new ClaimDesktopCalendar())->apply(new DataPatchContext($this->orm->getQueryAdapter()));
                foreach ($this->orm->getQueryAdapter()->execute("SELECT title, user_id FROM platform_calendar_events WHERE title LIKE 'claim-test %' ORDER BY title DESC")->rows as $row) {
                    $owners[substr((string) $row['title'], strlen('claim-test '))] = $row['user_id'] === null ? null : (string) $row['user_id'];
                }
                throw new ClaimDesktopCalendarRollback();
            });
        } catch (ClaimDesktopCalendarRollback) {
        }

        return $owners;
    }
}

final class ClaimDesktopCalendarRollback extends \RuntimeException
{
}
