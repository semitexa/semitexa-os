<?php

declare(strict_types=1);

namespace Semitexa\Os\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiExecutionKind;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Llm\Domain\Model\SkillEntry;
use Semitexa\Os\Application\Service\OsSkillText;

/**
 * Every app in the launcher described itself in English, on a console the person
 * had set to Ukrainian.
 *
 * The text lives in a PHP attribute — evaluated once and read long before anyone
 * knows which locale is being served — so it cannot be translated where it is
 * written. It is not moved either: the English stays as the fallback and the
 * translation is looked up by a key derived from the skill's name.
 */
final class OsSkillTextTest extends TestCase
{
    private function skill(string $name, string $summary): SkillEntry
    {
        return new SkillEntry(
            name: $name,
            sourceCommand: null,
            summary: $summary,
            useWhen: '',
            avoidWhen: '',
            riskLevel: AiRiskLevel::Low,
            confirmation: AiConfirmationMode::Never,
            supportsDryRun: false,
            argumentPolicy: AiArgumentPolicy::None,
            inputs: [],
            channels: ['ui'],
            executionKind: AiExecutionKind::DirectCommand,
            skillClass: null,
            icon: null,
            entry: null,
        );
    }

    #[Test]
    public function a_skill_nobody_translated_reads_exactly_as_it_did(): void
    {
        // The whole bargain: adding this must change nothing for a skill with no
        // catalog entry, in any install, including one with no i18n at all.
        $this->assertSame(
            'Generate a PDF report.',
            OsSkillText::summary($this->skill('my-app:report', 'Generate a PDF report.')),
        );
    }

    #[Test]
    public function the_key_is_derived_so_an_author_has_nothing_to_keep_in_sync(): void
    {
        $key = (new \ReflectionMethod(OsSkillText::class, 'key'))->invoke(null, 'tic-tac-toe', 'summary');

        $this->assertSame('os.skills.tic-tac-toe.summary', $key);
    }

    #[Test]
    public function one_app_cannot_become_two_keys(): void
    {
        $key = new \ReflectionMethod(OsSkillText::class, 'key');

        // 'TicTacToe', 'tic-tac-toe' and 'tic tac toe' are one app, and a
        // launcher entry that translated under one spelling and not another
        // would look like a missing translation rather than a naming slip.
        $this->assertSame(
            $key->invoke(null, 'tic-tac-toe', 'summary'),
            $key->invoke(null, 'Tic Tac Toe', 'summary'),
        );
        $this->assertSame('os.skills.content-list.summary', $key->invoke(null, 'content_list', 'summary'));
    }

    #[Test]
    public function the_os_ships_a_translation_for_every_app_in_its_own_launcher(): void
    {
        // The reference case. If a new OS app arrives without a line here, its
        // tile is the one English sentence on a Ukrainian screen.
        $dir = __DIR__ . '/../../../src/Application/View/locales/';
        $en = json_decode((string) file_get_contents($dir . 'en.json'), true);
        $uk = json_decode((string) file_get_contents($dir . 'uk.json'), true);

        $keys = array_values(array_filter(array_keys($en), static fn(string $k): bool => str_starts_with($k, 'skills.')));
        $this->assertNotSame([], $keys, 'no skill text is translated at all');

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $uk, $key . ' is English-only');
            $this->assertNotSame($en[$key], $uk[$key], $key . ' was copied, not translated');
        }
    }
}
