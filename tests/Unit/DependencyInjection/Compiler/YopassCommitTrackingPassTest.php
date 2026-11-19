<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Nowo\YopassBundle\DependencyInjection\Compiler\YopassCommitTrackingPass;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\EventListener\YopassConflictListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.21.0 (same rule as ContactFormBundle 1.11, audit UE-CF-1): the bundle's commit-tracking middleware never wraps the host's other connections by default, and is
 * not registered at all when transaction-doctrine's commit_detection already counts commits.
 */
#[CoversClass(YopassCommitTrackingPass::class)]
final class YopassCommitTrackingPassTest extends TestCase
{
    private const MIDDLEWARE = YopassExtension::COMMIT_TRACKING_MIDDLEWARE;

    public function testByDefaultOnlyTheConnectionOfTheBundleEntityManagerIsWrapped(): void
    {
        $container = $this->container('bundle', 'yopass');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.yopass_db'), 'the bundle connection');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'), 'never the host connection');
        self::assertSame(YopassExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(YopassConflictListener::class)->getArgument(1));
    }

    public function testWithoutDoctrineEntityManagerTheDefaultManagerConnectionIsWrapped(): void
    {
        $container = $this->container('bundle', null);
        $container->setParameter('doctrine.default_entity_manager', 'yopass');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.yopass_db'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testAnUnresolvableManagerFallsBackToTheDefaultConnection(): void
    {
        $container = $this->container('bundle', 'missing');
        $container->setParameter('doctrine.default_connection', 'yopass_db');
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.yopass_db'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));

        $container = $this->container('bundle', null);
        $container->getParameterBag()->remove(YopassExtension::COMMIT_TRACKING_PARAMETER);
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'no parameter: bundle mode; no default manager or connection: "default"');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.yopass_db'));
    }

    public function testAllWrapsEveryConnection(): void
    {
        $container = $this->container('all', 'yopass');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.yopass_db'));
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testTransactionDoctrineCommitDetectionIsReusedAndNoConnectionIsWrappedTwice(): void
    {
        $container = $this->container('all', 'yopass');
        $container->setDefinition('nowo_transaction_doctrine.commit_ledger', new Definition(CommitLedger::class));
        $container->setAlias(CommitLedger::class, 'nowo_transaction_doctrine.commit_ledger');

        $this->compile($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
        self::assertFalse($container->hasDefinition(YopassExtension::COMMIT_LEDGER_SERVICE));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.yopass_db'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
        self::assertSame(CommitLedger::class, (string) $container->getDefinition(YopassConflictListener::class)->getArgument(1));
    }

    public function testTransactionDoctrineLedgerWithoutTheListenerOnlyDropsTheMiddleware(): void
    {
        $container = $this->container('bundle', 'yopass');
        $container->removeDefinition(YopassConflictListener::class);
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));

        (new YopassCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    public function testOffRegistersNothingAndThePassLeavesTheContainerAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(YopassExtension::COMMIT_TRACKING_PARAMETER, 'off');

        (new YopassCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    private function container(string $mode, ?string $entityManager): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(YopassExtension::COMMIT_TRACKING_PARAMETER, $mode);
        $container->setParameter(YopassExtension::ENTITY_MANAGER_PARAMETER, $entityManager);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'yopass_db' => 'doctrine.dbal.yopass_db_connection']);
        $container->setDefinition('doctrine.dbal.default_connection.configuration', new Definition());
        $container->setDefinition('doctrine.dbal.yopass_db_connection.configuration', new Definition());
        $container->setDefinition('doctrine.orm.yopass_entity_manager', new Definition(null, [new Reference('doctrine.dbal.yopass_db_connection')]));
        $container->setDefinition(YopassExtension::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
        $container->setDefinition(self::MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(YopassExtension::COMMIT_LEDGER_SERVICE)]))
            ->addTag('doctrine.middleware'));
        $container->setDefinition(YopassConflictListener::class, new Definition(YopassConflictListener::class, [null, new Reference(YopassExtension::COMMIT_LEDGER_SERVICE), null]));

        return $container;
    }

    private function compile(ContainerBuilder $container): void
    {
        (new YopassCommitTrackingPass())->process($container);
        (new MiddlewaresPass())->process($container);
    }
}
