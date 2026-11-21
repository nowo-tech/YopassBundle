<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\DddHost;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function extension_loaded;

/**
 * 2.0.1 regression on SQLite (every CI job): a relation-mapped creator loaded by the application's entity manager works
 * with every bundle service on the private entity manager ({@see HostEntityCreatorScenario}).
 */
final class HostEntityCreatorKernelTest extends TestCase
{
    use HostEntityCreatorScenario;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
    }

    /**
     * @param array<string, mixed> $database
     */
    #[DataProvider('drivers')]
    public function testAHostLoadedCreatorWorksWithEveryBundleService(array $database): void
    {
        $this->assertHostLoadedCreatorWorksEverywhere($database, null, 'sqlite');
    }
}
