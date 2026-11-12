<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\YopassBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\YopassBundle\Doctrine\PrivateEntityManagerMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.20.0: the rollback middleware goes on the connection of `database.entity_manager` only.
 */
final class PrivateEntityManagerPassTest extends TestCase
{
    public function testNothingWithoutTheMiddleware(): void
    {
        $container = new ContainerBuilder();
        (new PrivateEntityManagerPass())->process($container);

        self::assertFalse($container->hasDefinition(PrivateEntityManagerMiddleware::class));
    }

    public function testTheMiddlewareGoesOnTheConnectionOfTheConfiguredManager(): void
    {
        $container = $this->container();
        $container->setParameter(PrivateEntityManagerPass::MANAGER_PARAMETER, 'shares');
        $container->setDefinition('doctrine.orm.shares_entity_manager', new Definition('stdClass', [new Reference('doctrine.dbal.archive_connection')]));
        (new PrivateEntityManagerPass())->process($container);

        self::assertSame([['connection' => 'archive']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    public function testWithoutAKnownManagerTheDefaultConnectionIsUsed(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.default_connection', 'main');
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'main']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $bare = $this->container();
        (new PrivateEntityManagerPass())->process($bare);
        self::assertSame([['connection' => 'default']], $bare->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('doctrine.middleware'));

        return $container;
    }
}
