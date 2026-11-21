<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection;

use Nowo\YopassBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\Doctrine\HostCommitEventRelay;
use Nowo\YopassBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Nowo\YopassBundle\Service\ShareCommands;
use Nowo\YopassBundle\Service\ShareConsumedNotifier;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareQuery;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Share\ShareAccessGuardInterface;
use Nowo\YopassBundle\Share\ShareCommandsInterface;
use Nowo\YopassBundle\Share\ShareQueryInterface;
use Nowo\YopassBundle\Share\ShareView;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 2.1.0: the id-based ports and the after-commit event relay are wired.
 */
final class YopassExtensionIdPortsTest extends TestCase
{
    public function testThePortsAreAliasedAndTheCommandsConfigured(): void
    {
        $container = $this->load([]);

        self::assertSame(ShareCommands::class, (string) $container->getAlias(ShareCommandsInterface::class));
        self::assertSame(ShareQuery::class, (string) $container->getAlias(ShareQueryInterface::class));
        self::assertSame(ShareAccessGuard::class, (string) $container->getAlias(ShareAccessGuardInterface::class));
        $commands = $container->getDefinition(ShareCommands::class);
        self::assertSame('%nowo_yopass.max_ciphertext_bytes%', $commands->getArgument('$maxCiphertextBytes'));
        self::assertFalse($commands->getArgument('$fileSharesEnabled'));
        self::assertFalse($container->hasDefinition(ShareView::class));
    }

    public function testTheOrmDriverRoutesTheAfterEventsThroughTheRelay(): void
    {
        if (!PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            self::markTestSkipped('doctrine/dbal 4 only.'); // @codeCoverageIgnore
        }
        $container = $this->load(['events' => ['after_host_commit' => true]]);

        self::assertTrue($container->hasDefinition(HostCommitEventRelay::class));
        self::assertTrue($container->getDefinition(HostCommitEventRelay::class)->hasTag('kernel.reset'));
        self::assertTrue($container->hasDefinition(YopassExtension::HOST_COMMIT_EVENTS_BOUNDARIES));
        self::assertEquals(new Reference(HostCommitEventRelay::class), $container->getDefinition(PrivateEntityManagerMiddleware::class)->getArgument('$events'));
        foreach ([ShareCreator::class, ShareDeleter::class, ShareRevoker::class, ShareExtender::class, ShareRetentionPurger::class, ShareConsumedNotifier::class, ShareCommands::class] as $service) {
            self::assertEquals(new Reference(HostCommitEventRelay::class), $container->getDefinition($service)->getArgument('$eventDispatcher'), $service);
        }
    }

    public function testTheRelayIsNotRegisteredWhenOffOrForAnotherDriver(): void
    {
        self::assertFalse($this->load([])->hasDefinition(HostCommitEventRelay::class), 'Opt-in in 2.x');
        self::assertFalse($this->load(['events' => ['after_host_commit' => false]])->hasDefinition(HostCommitEventRelay::class));
        self::assertFalse($this->load(['events' => ['after_host_commit' => true], 'database' => ['driver' => 'custom', 'platform' => 'mysql', 'repository' => 'app.shares']])->hasDefinition(HostCommitEventRelay::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.shares', new Definition(stdClass::class));
        (new YopassExtension())->load([['user_class' => 'App\\Entity\\User'] + $config], $container);

        return $container;
    }
}
