<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Semitexa\Os\Application\Service\SkinStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Revert the OS to its default (navy/cyan) look — clears the active skin. */
#[AsCommand(
    name: 'os:reset-skin',
    description: 'Revert the OS interface to its default look (clear the active skin).',
)]
#[AsAiSkill(
    name: 'reset-skin',
    summary: 'Revert the OS interface to its default look.',
    useWhen: 'The user wants to reset / undo / revert / remove the theme or skin, or go back to the default / original interface look (e.g. "reset the theme", "back to normal", "поверни стандартний вигляд").',
    avoidWhen: 'The user wants a NEW style (use design-skin).',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['web'],
)]
final class ResetSkinCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected SkinStore $skin;

    protected function configure(): void
    {
        $this->setName('os:reset-skin')
            ->setDescription('Revert the OS interface to its default look (clear the active skin).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->skin->clear();
        $output->writeln('Back to the default look.');

        return Command::SUCCESS;
    }
}
