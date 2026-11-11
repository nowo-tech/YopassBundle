<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nowo\YopassBundle\DependencyInjection\Compiler\AccessLogMappingPass;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function dirname;

final class AccessLogMappingPassTest extends TestCase
{
    public function testDisabledAccessLogIsNotMapped(): void
    {
        $classes = $this->mappedClasses(false);

        self::assertContains(SecureShare::class, $classes);
        self::assertNotContains(ShareAccessLog::class, $classes);
    }

    public function testEnabledAccessLogStaysMapped(): void
    {
        $classes = $this->mappedClasses(true);

        self::assertContains(SecureShare::class, $classes);
        self::assertContains(ShareAccessLog::class, $classes);
    }

    public function testHostAccessLogRepositoryUnmapsTheEntity(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(AccessLogMappingPass::ENABLED_PARAMETER, true);
        $container->setParameter(AccessLogMappingPass::MAPPED_PARAMETER, false);
        $container->setDefinition('doctrine.orm.default_attribute_metadata_driver', new Definition(AttributeDriver::class, [[]]));

        (new AccessLogMappingPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.default_attribute_metadata_driver')->getMethodCalls());
    }

    public function testWithoutParameterNothingChanges(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('doctrine.orm.default_attribute_metadata_driver', new Definition(AttributeDriver::class, [[]]));

        (new AccessLogMappingPass())->process($container);

        self::assertSame([], $container->getDefinition('doctrine.orm.default_attribute_metadata_driver')->getMethodCalls());
    }

    public function testOnlyOrmAttributeDriversAreTouched(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(AccessLogMappingPass::ENABLED_PARAMETER, false);
        $container->setDefinition('doctrine.orm.default_attribute_metadata_driver', new Definition(AttributeDriver::class, [[]]));
        $container->setDefinition('doctrine.orm.audit_attribute_metadata_driver', new Definition(AttributeDriver::class, [[]]));
        $container->setDefinition('doctrine.orm.default_xml_metadata_driver', new Definition('stdClass'));

        (new AccessLogMappingPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.default_attribute_metadata_driver')->getMethodCalls());
        self::assertCount(1, $container->getDefinition('doctrine.orm.audit_attribute_metadata_driver')->getMethodCalls());
        self::assertSame([], $container->getDefinition('doctrine.orm.default_xml_metadata_driver')->getMethodCalls());
    }

    /**
     * @return list<string>
     */
    private function mappedClasses(bool $accessLogEnabled): array
    {
        $container = new ContainerBuilder();
        $container->setParameter(AccessLogMappingPass::ENABLED_PARAMETER, $accessLogEnabled);
        $container->setDefinition('doctrine.orm.default_attribute_metadata_driver', (new Definition(AttributeDriver::class, [
            [dirname(__DIR__, 4) . '/src/Entity'],
        ]))->setPublic(true));

        (new AccessLogMappingPass())->process($container);

        /** @var AttributeDriver $driver */
        $driver = $container->get('doctrine.orm.default_attribute_metadata_driver');

        return $driver->getAllClassNames();
    }
}
