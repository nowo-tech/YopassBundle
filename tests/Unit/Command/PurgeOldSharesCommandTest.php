<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Command;

use Nowo\YopassBundle\Command\PurgeOldSharesCommand;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeOldSharesCommandTest extends TestCase
{
    public function testExecuteReportsDisabledRetention(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeOlderThan');

        $tester = new CommandTester(new PurgeOldSharesCommand(new ShareRetentionPurger($repository, ['retention' => ['enabled' => false, 'max_age' => '1 month']])));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('disabled', $tester->getDisplay());
    }

    public function testExecuteReportsRemovedShares(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('removeOlderThan')->willReturn(5);
        $repository->expects(self::once())->method('flush');

        $tester = new CommandTester(new PurgeOldSharesCommand(new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => '1 month']])));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Removed 5 share(s)', $tester->getDisplay());
    }
}
