<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Doctrine\ShareWriteUnit;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.21.0 (REQ-RUNTIME-003 rule 1): the bundle's write unit commits its work in one transaction, rolls it all back on a
 * failure, and never touches an open application manager.
 */
#[CoversClass(ShareWriteUnit::class)]
final class ShareWriteUnitTest extends TestCase
{
    private EntityManager $host;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $this->host = $this->manager();
        (new SchemaTool($this->host))->createSchema($this->host->getMetadataFactory()->getAllMetadata());
    }

    public function testThePrivateManagerCommitsTheUnitAndTheApplicationManagerIsNeverFlushed(): void
    {
        $registry = $this->registry($this->host);
        $own      = new YopassEntityManager($registry, 'default');
        $unit     = new ShareWriteUnit($this->host, $registry, 'default', $own);

        // A pending change of the application stays pending: the private manager is flushed, not the host's.
        $pending = $this->share('host');
        $this->host->persist($pending);

        $result = $unit->run(function () use ($own): string {
            $own->get()->persist($this->share('unit'));

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['unit'], $this->ids());
        self::assertTrue($this->host->getUnitOfWork()->isScheduledForInsert($pending));
    }

    public function testAFailureRollsTheWholeUnitBack(): void
    {
        $registry = $this->registry($this->host);
        $own      = new YopassEntityManager($registry, 'default');
        $unit     = new ShareWriteUnit($this->host, $registry, 'default', $own);

        try {
            $unit->run(function () use ($own): never {
                $own->get()->persist($this->share('first'));
                $own->get()->flush();

                throw new RuntimeException('second write failed');
            });
            self::fail('The failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('second write failed', $exception->getMessage());
        }

        self::assertSame([], $this->ids());
        self::assertSame(0, $this->host->getConnection()->getTransactionNestingLevel());
    }

    public function testLegacyModeRunsOnTheApplicationManagerAndRefusesItsPendingChanges(): void
    {
        $unit = new ShareWriteUnit($this->host, $this->registry($this->host), 'default');

        $unit->run(function (): void {
            $this->host->persist($this->share('legacy'));
        });
        self::assertSame(['legacy'], $this->ids());

        $this->host->persist($this->share('pending'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(DoctrineTransactionRunner::HOST_PENDING_MESSAGE);
        $unit->run(static fn (): null => null);
    }

    public function testLegacyModeReplacesAClosedApplicationManagerOnly(): void
    {
        $replacement = $this->manager($this->host);
        $current     = $this->host;
        $registry    = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(static function () use (&$current): EntityManager {
            return $current;
        });
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturnCallback(static function () use (&$current, $replacement): EntityManager {
            return $current = $replacement;
        });
        $this->host->close();

        (new ShareWriteUnit($this->host, $registry, 'default'))->run(function () use ($replacement): void {
            $replacement->persist($this->share('after-reset'));
        });

        self::assertSame(['after-reset'], $this->ids());
    }

    public function testLegacyModeWithoutARegistryUsesTheGivenManager(): void
    {
        (new ShareWriteUnit($this->host))->run(function (): void {
            $this->host->persist($this->share('no-registry'));
        });

        self::assertSame(['no-registry'], $this->ids());
    }

    public function testAnotherObjectFromTheRegistryFallsBackToTheGivenManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        (new ShareWriteUnit($this->host, $registry, 'default'))->run(function (): void {
            $this->host->persist($this->share('fallback'));
        });

        self::assertSame(['fallback'], $this->ids());
    }

    private function manager(?EntityManager $sameConnectionAs = null): EntityManager
    {
        $paths  = [dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-write-unit-proxies');
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new SecureShareMetadataListener('yopass_secure_shares', SecurityUserDto::class, null, CreatorMapping::ID));
        $connection = $sameConnectionAs?->getConnection() ?? DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        return new EntityManager($connection, $config, $events);
    }

    private function registry(EntityManager $manager): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return $registry;
    }

    private function share(string $id): SecureShare
    {
        $share = new SecureShare($id, 'alice');
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        return $share;
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        /** @var list<string> $ids */
        $ids = $this->host->getConnection()->fetchFirstColumn('SELECT id FROM yopass_secure_shares ORDER BY id');

        return $ids;
    }
}
