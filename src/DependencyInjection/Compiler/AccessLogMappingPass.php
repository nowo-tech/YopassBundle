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
 * access log: MongoDB, custom non-relational), or with a host `access_log.repository` (1.18.0), the entity file is excluded from every Doctrine ORM attribute
 * metadata driver, so `doctrine:schema:update` / `make:migration` do not create an empty
 * `<table_prefix>_share_access_logs` table.
 *
 * Runs on the merged container, after DoctrineBundle registered `doctrine.orm.<em>_attribute_metadata_driver`.
 */
final class AccessLogMappingPass implements CompilerPassInterface
{
    public const ENABLED_PARAMETER = 'nowo_yopass.access_log_enabled';

    /** False when the host stores the access log itself (`access_log.repository`, 1.18.0): the entity is not mapped. */
    public const MAPPED_PARAMETER = 'nowo_yopass.access_log_mapped';

    private const DRIVER_ID_PATTERN = '/^doctrine\.orm\.[^.]+_attribute_metadata_driver$/';

    /**
     * Excludes the ShareAccessLog entity file from the ORM attribute drivers when the access log is disabled.
     */
    public function process(ContainerBuilder $container): void
    {
        $disabled = $container->hasParameter(self::ENABLED_PARAMETER) && $container->getParameter(self::ENABLED_PARAMETER) === false;
        $unmapped = $container->hasParameter(self::MAPPED_PARAMETER) && $container->getParameter(self::MAPPED_PARAMETER) === false;
        if (!$disabled && !$unmapped) {
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
