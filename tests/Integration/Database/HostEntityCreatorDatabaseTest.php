<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Database;

use Nowo\YopassBundle\Tests\Integration\DddHost\HostEntityCreatorScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function getenv;
use function is_string;
use function str_contains;
use function str_starts_with;

/**
 * 2.0.1 regression on a database server (MySQL, MariaDB, PostgreSQL; CI jobs "Databases on ..."): a relation-mapped
 * creator loaded by the application's entity manager works with every bundle service on the private entity manager, with
 * the ORM driver and with a custom repository decorating the bundle's one ({@see HostEntityCreatorScenario}). Set
 * YOPASS_TEST_DATABASE_URL to run.
 */
#[Group('database')]
final class HostEntityCreatorDatabaseTest extends TestCase
{
    use HostEntityCreatorScenario;

    private string $url = '';

    protected function setUp(): void
    {
        $url = getenv('YOPASS_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set YOPASS_TEST_DATABASE_URL to run the host-loaded creator tests on a database server.');
        }
        $this->url = $url;
    }

    private function platform(): string
    {
        if (str_starts_with($this->url, 'postgres')) {
            return 'postgresql';
        }

        return str_contains($this->url, 'MariaDB') ? 'mariadb' : 'mysql';
    }

    /**
     * @param array<string, mixed> $database
     */
    #[DataProvider('drivers')]
    public function testAHostLoadedCreatorWorksWithEveryBundleService(array $database): void
    {
        $this->assertHostLoadedCreatorWorksEverywhere($database, $this->url, $this->platform());
    }
}
