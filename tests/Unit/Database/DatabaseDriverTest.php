<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Database;

use Nowo\YopassBundle\Database\DatabaseDriver;
use PHPUnit\Framework\TestCase;

final class DatabaseDriverTest extends TestCase
{
    public function testResolveDriverUsesMongoWhenPlatformIsMongo(): void
    {
        self::assertSame(
            DatabaseDriver::DOCTRINE_MONGODB,
            DatabaseDriver::resolveDriver(DatabaseDriver::DOCTRINE_ORM, DatabaseDriver::PLATFORM_MONGODB),
        );
    }

    public function testRelationalPlatformsExcludeMongo(): void
    {
        self::assertContains(DatabaseDriver::PLATFORM_POSTGRESQL, DatabaseDriver::relationalPlatforms());
        self::assertNotContains(DatabaseDriver::PLATFORM_MONGODB, DatabaseDriver::relationalPlatforms());
    }
}
