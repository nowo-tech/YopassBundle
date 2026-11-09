<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/demo',
        // Controllers used only via ::class strings for attribute route loading.
        __DIR__ . '/tests/Unit/Routing/YopassRouteLoaderTest.php',
        // The share is required on creation but nullable once stored (ON DELETE SET NULL): no promotion.
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__ . '/src/Entity/ShareAccessLog.php'],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
