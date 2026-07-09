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
 * Enable a keyboard layout by talking to the OS ("додай німецьку розкладку",
 * "add the German keyboard layout"). The planner extracts the language into
 * the `language` argument; this skill resolves it against the built-in
 * {@see InputLayoutCatalog} and persists it via {@see InputLayoutStore} — the
 * shell picks it up on the next preferences poll and shows it in the topbar
 * layout switcher.
 */
#[AsAiSkill(
    name: 'add-input-layout',
    summary: 'Enable a keyboard input layout (e.g. German, Ukrainian) in the OS layout switcher.',
    useWhen: 'The user wants a new keyboard/input layout available for typing — e.g. "додай німецьку розкладку", "add English layout", "додай українську клавіатуру", "enable the French keyboard". Put the language (name or two-letter code) in the `language` argument.',
    avoidWhen: 'The user wants the OS/assistant to SPEAK another language (that is set-locale), to switch between already-enabled layouts (the topbar switcher / hotkey does that), or to change the switch hotkey (set-layout-hotkey).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['language'],
    argumentHints: [
        'language' => 'Language of the layout to enable: a name ("німецька", "German", "français") or a two-letter code (en|uk|de|fr|es|pl).',
    ],
    channels: ['web'],
)]
final class AddInputLayoutSkill implements InvocableSkillInterface
{
    /** Tenant-aware via container injection (SkillLoopRunner's DI resolver). */
    #[InjectAsReadonly]
    protected InputLayoutStore $store;

    #[InjectAsReadonly]
    protected InputLayoutCatalog $catalog;

    public function invoke(array $arguments): string
    {
        $raw = (string) ($arguments['language'] ?? '');
        $code = $this->catalog()->resolve($raw);
        if ($code === null) {
            return ($raw === ''
                    ? 'Which layout would you like to add? '
                    : 'I don\'t have a "' . trim($raw) . '" layout yet. ')
                . 'Available: ' . $this->catalog()->describeSupported() . '.';
        }

        $alreadyEnabled = in_array($code, $this->store()->enabledCodes(), true);
        $label = $this->store()->add($code);
        $hotkey = $this->store()->hotkey()->label();

        return $alreadyEnabled
            ? 'The ' . $label . ' layout is already enabled — switch with ' . $hotkey . ' or the topbar switcher.'
            : 'Done — added the ' . $label . ' layout. Switch layouts with ' . $hotkey . ' or click the layout badge in the topbar.';
    }

    private function store(): InputLayoutStore
    {
        return isset($this->store) ? $this->store : new InputLayoutStore();
    }

    private function catalog(): InputLayoutCatalog
    {
        return isset($this->catalog) ? $this->catalog : new InputLayoutCatalog();
    }
}
