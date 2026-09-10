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
use Semitexa\Os\Application\Service\UiSkillDialog;

/**
 * A UI skill raised from chat has to be able to open AT something.
 *
 * It could not. handleUiSkill() opened the dialog with the bare entry URL and
 * dropped the planner's arguments on the floor, so "open the pricing page"
 * opened the editor at nothing. The shell path had carried a ref all along; only
 * the chat path was blind.
 *
 * The three decisions live in UiSkillDialog: which of the planner's arguments
 * may reach the entry URL, what that URL becomes, and what the window is called.
 * None touches the runner's state, which is why they left it.
 */
final class UiSkillArgumentsTest extends TestCase
{
    /**
     * @param array<string, array{type: string, required: bool, description: string}> $inputs
     */
    private function entry(array $inputs, ?string $url = '/os/app/cms'): SkillEntry
    {
        return new SkillEntry(
            name: 'Content',
            sourceCommand: null,
            summary: '',
            useWhen: '',
            avoidWhen: '',
            riskLevel: AiRiskLevel::Low,
            confirmation: AiConfirmationMode::Never,
            supportsDryRun: false,
            argumentPolicy: AiArgumentPolicy::Allowlisted,
            inputs: $inputs,
            channels: ['ui'],
            executionKind: AiExecutionKind::DirectCommand,
            skillClass: null,
            icon: null,
            entry: $url,
        );
    }

    /** @param array{type?: string, required?: bool, description?: string} $over */
    private function input(array $over = []): array
    {
        return ['type' => 'string', 'required' => false, 'description' => ''] + $over;
    }

    /**
     * Public now: these three moved out of SkillLoopRunner when the structural
     * budget caught it growing, and the test no longer has to reach in.
     */
    private function call(string $method, mixed ...$args): mixed
    {
        return UiSkillDialog::$method(...$args);
    }

    #[Test]
    public function a_declared_argument_reaches_the_entry_url(): void
    {
        $applied = $this->call('query', $this->entry(['ref' => $this->input()]), ['ref' => 'page:pricing']);
        $this->assertSame(['ref' => 'page:pricing'], $applied);

        $url = $this->call('entryUrl', '/os/app/cms', $applied);
        $this->assertSame('/os/app/cms?ref=page%3Apricing', $url);
    }

    #[Test]
    public function an_argument_the_skill_never_declared_is_dropped(): void
    {
        // The entry is a URL the OS opens in a window, so anything reaching it is
        // appended to that app's own query string. A planner's argument names are
        // a suggestion, not a contract.
        $applied = $this->call(
            'query',
            $this->entry(['ref' => $this->input()]),
            ['ref' => 'page:pricing', 'redirect' => 'https://elsewhere.example', 'admin' => '1'],
        );

        $this->assertSame(['ref' => 'page:pricing'], $applied);
    }

    #[Test]
    public function an_argument_less_ui_skill_opens_exactly_where_it_always_did(): void
    {
        // Notes, Calendar, Terminal: nothing declared, nothing appended.
        $applied = $this->call('query', $this->entry([]), ['ref' => 'page:pricing']);
        $this->assertSame([], $applied);
        $this->assertSame('/os/app/notes', $this->call('entryUrl', '/os/app/notes', []));
    }

    #[Test]
    public function a_skill_with_no_entry_url_stays_without_one(): void
    {
        $this->assertNull($this->call('entryUrl', null, ['ref' => 'x']));
    }

    #[Test]
    public function an_entry_that_already_has_a_query_gets_an_ampersand(): void
    {
        $url = $this->call('entryUrl', '/os/app/cms?mode=edit', ['ref' => 'page:pricing']);
        $this->assertSame('/os/app/cms?mode=edit&ref=page%3Apricing', $url);
    }

    #[Test]
    public function declaration_order_decides_the_url_not_the_planner(): void
    {
        // The same plan must always produce the same URL, whatever order the model
        // happened to emit the arguments in.
        $entry = $this->entry(['ref' => $this->input(), 'mode' => $this->input()]);

        $this->assertSame(
            $this->call('query', $entry, ['mode' => 'edit', 'ref' => 'a']),
            $this->call('query', $entry, ['ref' => 'a', 'mode' => 'edit']),
        );
        $this->assertSame(['ref' => 'a', 'mode' => 'edit'], $this->call('query', $entry, ['mode' => 'edit', 'ref' => 'a']));
    }

    #[Test]
    public function the_window_is_named_after_what_the_person_asked_for(): void
    {
        // Five pages opened from chat used to give five windows called 'Content'
        // — the app's name, not the place's — so there was nothing to switch by.
        // The chat path cannot look the record up here (the argument is still
        // what the person SAID), so it shows exactly that beside the app rather
        // than claiming a title it has not resolved.
        $this->assertSame('Content — Контакти', $this->call('title', 'Content', ['name' => 'Контакти']));
    }

    #[Test]
    public function an_argument_less_window_keeps_the_plain_skill_name(): void
    {
        $this->assertSame('Notes', $this->call('title', 'Notes', []));
        $this->assertSame('Notes', $this->call('title', 'Notes', ['name' => '  ']));
    }

    #[Test]
    public function a_long_argument_does_not_run_away_with_the_title_bar(): void
    {
        $title = $this->call('title', 'Content', ['name' => str_repeat('x', 80)]);

        $this->assertStringStartsWith('Content — ', $title);
        $this->assertStringEndsWith('…', $title);
        $this->assertSame(40, mb_strlen(explode(' — ', $title, 2)[1]));
    }

    #[Test]
    public function non_scalar_and_null_arguments_are_skipped_and_flags_survive_the_url(): void
    {
        $entry = $this->entry([
            'ref' => $this->input(),
            'draft' => $this->input(['type' => 'flag']),
            'empty' => $this->input(),
            'list' => $this->input(['type' => 'array']),
        ]);

        $applied = $this->call('query', $entry, [
            'ref' => 'page:pricing',
            'draft' => true,
            'empty' => null,
            'list' => ['a', 'b'],
        ]);

        // '1'/'0' rather than 'true'/'' — a flag has to be readable by a plain
        // string comparison on the other side of the URL.
        $this->assertSame(['ref' => 'page:pricing', 'draft' => '1'], $applied);
    }
}
