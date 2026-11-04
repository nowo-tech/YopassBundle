<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection\Compiler;

use Nowo\YopassBundle\Entity\ShareAccessLog;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function preg_match;

/**
 * Conditional mapping of {@see ShareAccessLog}: with `access_log.enabled: false` (or a backend without the ORM
 * access log: MongoDB, custom non-relational), the entity file is excluded from every Doctrine ORM attribute
 * metadata driver, so `doctrine:schema:update` / `make:migration` do not create an empty
 * `<table_prefix>_share_access_logs` table.
 *
 * Runs on the merged container, after DoctrineBundle registered `doctrine.orm.<em>_attribute_metadata_driver`.
 */
final class AccessLogMappingPass implements CompilerPassInterface
{
    public const ENABLED_PARAMETER = 'nowo_yopass.access_log_enabled';

    private const DRIVER_ID_PATTERN = '/^doctrine\.orm\.[^.]+_attribute_metadata_driver$/';

    /**
     * Excludes the ShareAccessLog entity file from the ORM attribute drivers when the access log is disabled.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::ENABLED_PARAMETER) || $container->getParameter(self::ENABLED_PARAMETER) !== false) {
            return;
        }

        $file = (string) (new ReflectionClass(ShareAccessLog::class))->getFileName();

        foreach ($container->getDefinitions() as $id => $definition) {
            if (preg_match(self::DRIVER_ID_PATTERN, $id) === 1) {
                $definition->addMethodCall('addExcludePaths', [[$file]]);
            }
        }
    }
}
