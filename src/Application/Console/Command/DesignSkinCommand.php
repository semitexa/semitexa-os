<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Os\Application\Service\SkinStore;
use Semitexa\PlatformUi\Application\Service\SkinResolver\Llm\PromptResolverFactory;
use Semitexa\Theme\Application\Service\Skin\KnobResolver;
use Semitexa\Theme\Application\Service\Skin\SkinAlgorithmRegistry;
use Semitexa\Theme\Application\Service\Skin\SkinBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reskin the whole OS from a described mood — "make the interface look like a
 * seaside evening in Sicily". The prompt goes through the existing skin engine
 * (PromptResolver → algorithm + seed + knobs → SkinBuilder dual palette), the
 * DARK palette is mapped onto the tokenized shell variables, and stored in
 * SkinStore. The shell's /os/skin poll then applies it live.
 *
 * A command (not a bare InvocableSkill) so it gets DI — the LLM provider the
 * generation needs. `--hex` gives a deterministic path (no LLM) for testing.
 */
#[AsCommand(
    name: 'os:design-skin',
    description: 'Reskin the OS interface to match a described mood/style (LLM) or a seed hex.',
)]
#[AsAiSkill(
    name: 'design-skin',
    summary: 'Restyle the whole OS interface to match a described mood or style.',
    useWhen: 'The user wants to restyle / retheme / reskin / redesign the OS interface, or asks for the interface "in the style of …" / "in a … mood" (e.g. "make the interface look like a seaside evening in Sicily", "зроби дизайн інтерфейсу в стилі заходу сонця на морі", "restyle the OS warm and cozy"). Put the FULL description in `prompt`.',
    avoidWhen: 'The user wants to RESET/undo the theme (use reset-skin), or is not talking about the interface appearance.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['prompt'],
    channels: ['web'],
)]
final class DesignSkinCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected LlmProviderResolver $llm;

    #[InjectAsReadonly]
    protected SkinStore $skin;

    protected function configure(): void
    {
        $this->setName('os:design-skin')
            ->setDescription('Reskin the OS interface to match a described mood/style (LLM) or a seed hex.')
            ->addOption('prompt', null, InputOption::VALUE_REQUIRED, 'Natural-language description of the desired look/mood.')
            ->addOption('hex', null, InputOption::VALUE_REQUIRED, 'Seed colour #rrggbb (deterministic, skips the LLM).')
            ->addOption('llm', null, InputOption::VALUE_NONE, 'Use the LLM resolver for a smarter seed (slow on a cold model; keyword-first otherwise).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompt = trim((string) $input->getOption('prompt'));
        $hex = trim((string) $input->getOption('hex'));

        $seed = $hex;
        $algorithmId = 'balanced';
        $knobs = [];
        $label = $hex;

        if ($seed === '') {
            if ($prompt === '') {
                $output->writeln('Tell me the style — e.g. "a seaside evening in Sicily".');
                return Command::SUCCESS;
            }
            $label = $prompt;
            // Keyword-first: derive the seed from the mood INSTANTLY + reliably, so a
            // chat turn always reskins the OS. The LLM resolver picks a smarter seed,
            // but on the cold remote model it can block for minutes (provider timeout
            // × retries) — far too long to hold a chat turn — so it is opt-in via
            // --llm (use it when the model is warm); otherwise we never touch it.
            $seed = $this->seedFromPrompt($prompt);
            if ($input->getOption('llm')) {
                try {
                    $resolution = (new PromptResolverFactory(provider: $this->llm->provider()))->create()->resolve($prompt);
                    if ($this->isHex((string) $resolution->params->seed)) {
                        $seed = (string) $resolution->params->seed;
                        $algorithmId = $resolution->params->algorithm;
                        $knobs = $resolution->params->knobs;
                    }
                } catch (\Throwable) {
                    // Keep the keyword seed — the model was slow/unavailable.
                }
            }
        }

        $registry = new SkinAlgorithmRegistry();
        if (!$registry->has($algorithmId)) {
            $algorithmId = 'balanced';
        }
        $algorithm = $registry->get($algorithmId);
        $resolvedKnobs = KnobResolver::resolve($knobs, $algorithm->knobSchema());
        $dual = (new SkinBuilder())->buildDualPalette($algorithm, $seed, $resolvedKnobs);

        $this->skin->set($this->mapDarkPalette($dual->dark), $label);

        $output->writeln('Reskinned the interface to match "' . $label . '". Say "reset the theme" to go back.');

        return Command::SUCCESS;
    }

    /**
     * Map the generated DARK --ui-* palette onto the five tokenized shell vars.
     *
     * @param array<string, string> $dark
     * @return array<string, string>
     */
    private function mapDarkPalette(array $dark): array
    {
        $accent = $dark['--ui-accent-brand'] ?? '#37b7ff';
        $page   = $dark['--ui-surface-page'] ?? '#0f172a';
        $panel  = $dark['--ui-surface-panel'] ?? '#0f172a';
        $sunken = $dark['--ui-surface-sunken'] ?? $page;
        $text   = $dark['--ui-text-primary'] ?? '#eaf2ff';
        $accentRgb = $this->hexToRgb($accent);

        return [
            '--accent' => $accent,
            '--accent-rgb' => $accentRgb,
            '--os-surface-rgb' => $this->hexToRgb($panel),
            '--os-text' => $text,
            '--os-bg' => \sprintf(
                'radial-gradient(1100px 620px at 18%% 8%%, rgba(%s,.20), transparent 60%%), '
                . 'radial-gradient(900px 560px at 86%% 4%%, rgba(%s,.12), transparent 55%%), '
                . 'linear-gradient(160deg, %s, %s)',
                $accentRgb,
                $accentRgb,
                $page,
                $sunken,
            ),
        ];
    }

    private function isHex(string $v): bool
    {
        return preg_match('/^#?[0-9a-fA-F]{6}$/', trim($v)) === 1;
    }

    /**
     * Deterministic prompt → seed colour, so a mood still reskins the OS when the
     * LLM resolver is slow/unavailable. Scans for the first colour/mood keyword
     * (EN + UK); defaults to the Semitexa cyan.
     */
    private function seedFromPrompt(string $prompt): string
    {
        $p = mb_strtolower($prompt);
        $map = [
            '#2e9e4f' => ['green', 'forest', 'ліс', 'зелен', 'emerald', 'mint', 'moss'],
            '#1e7fb8' => ['sea', 'ocean', 'blue', 'море', 'син', 'блакит', 'water', 'sky'],
            '#e8703a' => ['sunset', 'sundown', 'dusk', 'orange', 'warm', 'захід', 'захо', 'помаранч', 'ember', 'autumn', 'осін', 'вечір', 'вечор'],
            '#c0392b' => ['red', 'crimson', 'червон', 'fire', 'ruby'],
            '#7b57c2' => ['purple', 'violet', 'фіолет', 'lavender', 'plum', 'бузков'],
            '#d6547a' => ['pink', 'rose', 'рожев', 'blossom'],
            '#d9a520' => ['yellow', 'gold', 'жовт', 'золот', 'sun', 'honey', 'сонц'],
            '#17a89a' => ['teal', 'cyan', 'бірюз', 'turquoise'],
            '#2c3e50' => ['dark', 'night', 'ніч', 'темн', 'midnight', 'noir'],
            '#8d6e4a' => ['brown', 'wood', 'earth', 'коричн', 'земл', 'coffee', 'sand'],
        ];
        foreach ($map as $hex => $words) {
            foreach ($words as $w) {
                if (str_contains($p, $w)) {
                    return $hex;
                }
            }
        }

        return '#37b7ff';
    }

    /** "#rrggbb" → "r, g, b" (falls back to the default accent on a bad value). */
    private function hexToRgb(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '55, 183, 255';
        }

        return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
    }
}
