<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Command;

use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:yopass:purge-old-shares',
    description: 'Delete Yopass shares older than the configured retention age',
)]
final class PurgeOldSharesCommand extends Command
{
    public function __construct(
        private readonly ShareRetentionPurger $retentionPurger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->retentionPurger->isEnabled()) {
            $io->warning('Share retention is disabled in nowo_yopass.shares.retention.enabled.');

            return Command::SUCCESS;
        }

        $removed = $this->retentionPurger->purgeAll();

        if ($removed === 0) {
            $io->success('No shares matched the retention policy.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Removed %d share(s) older than the configured retention age.', $removed));

        return Command::SUCCESS;
    }
}
