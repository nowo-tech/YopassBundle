<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;

use function dirname;
use function extension_loaded;
use function hash;
use function method_exists;
use function sort;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.16 (owner decision): the read audit trail outlives its share. Deleting a creator deletes their shares (ON DELETE
 * CASCADE) but every access log row stays, with `share_id` NULL and the SHA-256 share reference kept.
 */
final class AccessLogSurvivesShareDeletionTest extends TestCase
{
    private const SHARE_A = '00000000-0000-4000-8000-0000000000a1';

    private const SHARE_B = '00000000-0000-4000-8000-0000000000b1';

    private EntityManager $em;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-log-proxies');
        $this->enableLazyObjects($config);

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new SecureShareMetadataListener('yopass_secure_shares', GuardUser::class, 'yopass_share_access_logs'));

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        // SQLite applies ON DELETE actions only with foreign keys on.
        $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testDeletingACreatorDeletesTheSharesButKeepsTheirAccessLogs(): void
    {
        $alice = new GuardUser(1, 'alice');
        $bob   = new GuardUser(2, 'bob');
        $a     = $this->share(self::SHARE_A, $alice);
        $b     = $this->share(self::SHARE_B, $bob);
        foreach ([$alice, $bob, $a, $b, $this->log('l1', $a, 1), $this->log('l2', $a, 2), $this->log('l3', $b, 1)] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $user = $this->em->find(GuardUser::class, 1);
        self::assertInstanceOf(GuardUser::class, $user);
        $this->em->remove($user);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->find(SecureShare::class, self::SHARE_A), 'The creator\'s share is gone');
        self::assertInstanceOf(SecureShare::class, $this->em->find(SecureShare::class, self::SHARE_B));
        self::assertSame(3, $this->rows(), 'No access log row was deleted');

        $logs = new DoctrineOrmShareAccessLogRepository($this->em);
        $kept = $logs->findByShareId(self::SHARE_A);
        self::assertCount(2, $kept);
        foreach ($kept as $log) {
            self::assertNull($log->getShare());
            self::assertSame(hash('sha256', self::SHARE_A), $log->getShareRef());
        }
        $reads = [$kept[0]->getReadNumber(), $kept[1]->getReadNumber()];
        sort($reads);
        self::assertSame([1, 2], $reads);
        self::assertSame(self::SHARE_B, $logs->findByShareId(self::SHARE_B)[0]->getShare()?->getId());
    }

    public function testDeletingAShareKeepsItsAccessLogs(): void
    {
        $bob = new GuardUser(2, 'bob');
        $b   = $this->share(self::SHARE_B, $bob);
        foreach ([$bob, $b, $this->log('l3', $b, 1)] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $shares = new DoctrineOrmShareRepository($this->em);
        $share  = $shares->find(self::SHARE_B);
        self::assertInstanceOf(SecureShare::class, $share);
        (new ShareDeleter($shares))->delete($share);
        $this->em->clear();

        self::assertNull($this->em->find(SecureShare::class, self::SHARE_B));
        self::assertSame(1, $this->rows());
        $log = (new DoctrineOrmShareAccessLogRepository($this->em))->findByShareId(self::SHARE_B)[0];
        self::assertNull($log->getShare());
        self::assertSame(1, $log->getReadNumber());

        // Bulk deletes (delete all, retention) are DQL deletes: the database keeps the rows too.
        $c = $this->share(self::SHARE_A, $this->em->getReference(GuardUser::class, 2));
        $this->em->persist($c);
        $this->em->persist($this->log('l4', $c, 1));
        $this->em->flush();
        self::assertSame(1, $shares->removeAllByCreator($this->em->getReference(GuardUser::class, 2)));
        self::assertSame(2, $this->rows());
    }

    private function share(string $id, object $creator): SecureShare
    {
        $share = new SecureShare($id, $creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(3);

        return $share;
    }

    private function log(string $id, SecureShare $share, int $read): ShareAccessLog
    {
        return new ShareAccessLog('00000000-0000-4000-8000-0000000000' . $id, $share, $read, '203.0.113.7');
    }

    private function rows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM yopass_share_access_logs');
    }

    private function enableLazyObjects(Configuration $config): void
    {
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
    }
}
