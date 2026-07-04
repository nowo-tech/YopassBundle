<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\DependencyInjection\Compiler\ShareFileHandlerPass;
use Nowo\YopassBundle\Service\ShareFileHandlerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ShareFileHandlerPassTest extends TestCase
{
    public function testWiresConfiguredFileHandler(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_yopass.file_handler', 'app.yopass.file_handler');
        $container->setDefinition('app.yopass.file_handler', new Definition(stdClass::class));
        $container->setDefinition(ShareManageController::class, new Definition(ShareManageController::class));

        (new ShareFileHandlerPass())->process($container);

        self::assertSame(
            'app.yopass.file_handler',
            (string) $container->getAlias(ShareFileHandlerInterface::class),
        );
    }

    public function testFailsWhenHandlerServiceMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_yopass.file_handler', 'missing.handler');

        $this->expectException(InvalidConfigurationException::class);
        (new ShareFileHandlerPass())->process($container);
    }
}
