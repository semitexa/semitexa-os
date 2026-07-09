<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * Configure the layout-switch key combination by talking to the OS
 * ("переключай розкладку по Ctrl+Space", "switch layouts with Win+Space").
 * The planner extracts the combo into the `combo` argument; {@see InputHotkey}
 * parses loose phrasing and {@see InputLayoutStore} persists it — the shell's
 * keydown listener re-arms on the next preferences poll.
 */
#[AsAiSkill(
    name: 'set-layout-hotkey',
    summary: 'Set the keyboard shortcut that switches input layouts.',
    useWhen: 'The user wants a different key combination for switching keyboard layouts — e.g. "переключай розкладку по Ctrl+Space", "зроби переключення мови на Alt+Shift", "switch layouts with Win+Space", "постав капс для зміни розкладки". Put the combination in the `combo` argument, e.g. "Ctrl+Space".',
    avoidWhen: 'The user wants to add/remove a layout (add-input-layout / remove-input-layout) or to switch the layout right now (topbar switcher).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['combo'],
    argumentHints: [
        'combo' => 'Key combination: modifiers joined by "+", optionally ending in Space, Backquote, or CapsLock — e.g. "Alt+Shift", "Ctrl+Space", "Win+Space", "Ctrl+Shift".',
    ],
    channels: ['web'],
)]
final class SetLayoutHotkeySkill implements InvocableSkillInterface
{
    /** Tenant-aware via container injection (SkillLoopRunner's DI resolver). */
    #[InjectAsReadonly]
    protected InputLayoutStore $store;

    public function invoke(array $arguments): string
    {
        $raw = (string) ($arguments['combo'] ?? '');
        if (trim($raw) === '') {
            return 'Which combination would you like — e.g. Alt+Shift, Ctrl+Space, Win+Space, or CapsLock with a modifier?';
        }

        $hotkey = InputHotkey::parse($raw);
        if ($hotkey === null) {
            return 'I couldn\'t read "' . trim($raw) . '" as a shortcut. Use modifiers (Ctrl, Alt, Shift, Win) plus optionally Space, ` or CapsLock — e.g. "Ctrl+Space" or "Alt+Shift".';
        }

        $this->store()->setHotkey($hotkey);

        return 'Done — layouts now switch with ' . $hotkey->label() . '.';
    }

    private function store(): InputLayoutStore
    {
        return isset($this->store) ? $this->store : new InputLayoutStore();
    }
}
