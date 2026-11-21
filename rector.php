<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/demo',
        // Controllers used only via ::class strings for attribute route loading.
        __DIR__ . '/tests/Unit/Routing/YopassRouteLoaderTest.php',
        // The share is required on creation but nullable once stored (ON DELETE SET NULL): no promotion.
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__ . '/src/Entity/ShareAccessLog.php'],
        // get() declares the ODM DocumentManager on purpose: naming the internal subclass makes Symfony's opcache
        // preloader compile it in applications without doctrine/mongodb-odm ("Can't preload unlinked class").
        NarrowObjectReturnTypeRector::class => [__DIR__ . '/src/Doctrine/YopassDocumentManager.php'],
        RemoveUselessReturnTagRector::class => [__DIR__ . '/src/Doctrine/YopassDocumentManager.php'],
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
