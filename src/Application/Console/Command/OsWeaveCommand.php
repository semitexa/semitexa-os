<?php

declare(strict_types=1);

namespace Semitexa\Os\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Os\Application\Service\Weaver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run one weave pass by hand — the same extraction the background timer does,
 * but immediate (the idle gate is skipped) and with the result printed. The
 * testing/debug seam for the weaver: on the VM, in dev, or with `OS_WEAVER=off`.
 */
#[AsCommand(
    name: 'os:weave',
    description: 'Weave unprocessed conversation turns into the knowledge graph (one LLM extraction pass).',
)]
final class OsWeaveCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected Weaver $weaver;

    protected function configure(): void
    {
        $this->addOption('reset-cursor', null, InputOption::VALUE_NONE, 'Re-weave from the beginning of the transcript (writes are idempotent).');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Keep weaving until the transcript is fully consumed, not just one batch.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption('reset-cursor')) {
            $this->weaver->resetCursor();
            $output->writeln('<comment>Cursor reset — weaving from the beginning.</comment>');
        }

        $passes = 0;
        do {
            $result = $this->weaver->weave(ignoreIdleGate: true);
            $passes++;
            $output->writeln(sprintf(
                '<info>pass %d:</info> %s — %d turn(s), %d node(s), %d edge(s)',
                $passes,
                $result['status'],
                $result['turns'],
                $result['nodes'],
                $result['edges'],
            ));
            foreach ($result['detail'] as $line) {
                $output->writeln('  ' . $line);
            }
            if ($result['status'] === 'llm-unreachable') {
                $output->writeln('<error>LLM provider unreachable — cursor untouched, batch will retry.</error>');

                return Command::FAILURE;
            }
        } while ((bool) $input->getOption('all') && $result['status'] !== 'idle');

        return Command::SUCCESS;
    }
}
