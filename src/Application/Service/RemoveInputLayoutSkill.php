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
 * Disable an enabled keyboard layout ("прибери польську розкладку", "remove
 * the French layout"). The pass-through English layout is the safety fallback
 * and stays — {@see InputLayoutStore::remove()} refuses it. Reversible via
 * add-input-layout, so no confirmation gate.
 */
#[AsAiSkill(
    name: 'remove-input-layout',
    summary: 'Disable a keyboard input layout from the OS layout switcher.',
    useWhen: 'The user wants a keyboard layout removed from the switcher — e.g. "прибери німецьку розкладку", "remove the Polish layout", "видали французьку клавіатуру". Put the language (name or two-letter code) in the `language` argument.',
    avoidWhen: 'The user wants to switch the active layout (topbar switcher / hotkey) or change the OS language (set-locale).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['language'],
    argumentHints: [
        'language' => 'Language of the layout to remove: a name ("німецька", "German") or a two-letter code (uk|de|fr|es|pl).',
    ],
    channels: ['web'],
)]
final class RemoveInputLayoutSkill implements InvocableSkillInterface
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
            return 'Which layout should I remove? Currently enabled: ' . $this->enabledLabels() . '.';
        }

        if (!in_array($code, $this->store()->enabledCodes(), true)) {
            return 'That layout is not enabled. Currently enabled: ' . $this->enabledLabels() . '.';
        }

        $label = (string) ($this->catalog()->get($code)['label'] ?? $code);

        try {
            $this->store()->remove($code);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return 'Done — removed the ' . $label . ' layout.';
    }

    private function enabledLabels(): string
    {
        $labels = [];
        foreach ($this->store()->enabledCodes() as $code) {
            $labels[] = (string) ($this->catalog()->get($code)['label'] ?? $code);
        }

        return implode(', ', $labels);
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
