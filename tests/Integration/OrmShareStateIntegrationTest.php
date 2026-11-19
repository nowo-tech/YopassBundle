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
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\YopassBundle\Doctrine\ShareWriteUnit;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;
use function dirname;
use function extension_loaded;
use function method_exists;
use function sprintf;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * The ORM path keeps read counts and revocation dates across saves (the MongoDB path did not, DDD audit 2026-11-09).
 */
final class OrmShareStateIntegrationTest extends TestCase
{
    private const ID = '00000000-0000-4000-8000-0000000000e1';

    private EntityManager $em;

    private DoctrineOrmShareRepository $repository;

    private GuardUser $creator;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-state-proxies');
        $this->enableLazyObjects($config);

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, GuardUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->creator = new GuardUser(1, 'alice');
        $this->em->persist($this->creator);
        $this->em->flush();

        $this->repository = new DoctrineOrmShareRepository($this->em);
    }

    public function testExtendingAfterReadsKeepsTheReadsLeft(): void
    {
        $share = new SecureShare(self::ID, $this->creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(3);
        $this->repository->persist($share);
        $this->repository->flush();

        self::assertNotNull($this->repository->consumeReadIfAvailable(self::ID));
        self::assertNotNull($this->repository->consumeReadIfAvailable(self::ID));

        $loaded = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $loaded);
        (new ShareExtender($this->repository, DefaultShareOptions::get()))->extend($loaded, '24h', null);
        $this->em->clear();

        self::assertSame(1, $this->repository->find(self::ID)?->getReadsLeft());
    }

    public function testRevocationDateIsKeptAcrossSaves(): void
    {
        $share = new SecureShare(self::ID, $this->creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(3);
        $this->repository->persist($share);
        $this->repository->flush();

        (new ShareRevoker($this->repository))->revoke($share);
        $revokedAt = $share->getRevokedAt()?->format('Y-m-d H:i:s');
        $this->em->clear();

        $loaded = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $loaded);
        $this->repository->persist($loaded);
        $this->repository->flush();
        $this->em->clear();

        $again = $this->repository->find(self::ID);
        self::assertSame($revokedAt, $again?->getRevokedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(0, $again?->getReadsLeft());
        self::assertNull($this->repository->consumeReadIfAvailable(self::ID));
    }

    private function enableLazyObjects(Configuration $config): void
    {
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
    }

    /**
     * 1.21.0 (REQ-RUNTIME-003 rule 9): the retention purge deletes in chunks of 500 by primary key, re-checking the
     * cutoff, and keeps the shares created after it.
     */
    public function testTheRetentionPurgeDeletesOldSharesInChunks(): void
    {
        $connection = $this->em->getConnection();
        for ($i = 0; $i < 501; ++$i) {
            $connection->insert('yopass_secure_shares', [
                'id'        => sprintf('00000000-0000-4000-8000-%012d', $i), 'creator_id' => 1, 'ciphertext' => 'c', 'expires_at' => '2030-01-01 00:00:00',
                'max_reads' => 1, 'reads_left' => 1, 'created_at' => '2020-01-01 00:00:00', 'payload_kind' => 'text',
            ]);
        }
        $kept = new SecureShare(self::ID, $this->creator);
        $kept->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);
        $this->repository->persist($kept);
        $this->repository->flush();

        self::assertSame(501, $this->repository->removeOlderThan(new DateTimeImmutable('2021-01-01')));
        self::assertSame(0, $this->repository->removeOlderThan(new DateTimeImmutable('2021-01-01')));
        self::assertSame([self::ID], $connection->fetchFirstColumn('SELECT id FROM yopass_secure_shares'));
    }

    /**
     * 1.21.0 (REQ-RUNTIME-003 rule 4): the expiration only moves forward, and never on a revoked share.
     */
    public function testTheExpirationOnlyMovesForward(): void
    {
        $share = new SecureShare(self::ID, $this->creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);
        $this->repository->persist($share);
        $this->repository->flush();

        $later = new DateTimeImmutable('+7 days');
        self::assertTrue($this->repository->extendExpiration($share, $later));
        self::assertSame($later->format('Y-m-d H:i:s'), $share->getExpiresAt()->format('Y-m-d H:i:s'), 'the managed share is refreshed');
        self::assertFalse($this->repository->extendExpiration($share, new DateTimeImmutable('+1 day')), 'never moved back');
        self::assertSame($later->format('Y-m-d H:i:s'), $share->getExpiresAt()->format('Y-m-d H:i:s'));

        $this->em->detach($share);
        (new ShareRevoker($this->repository))->revoke($this->repository->find(self::ID) ?? $share);
        self::assertFalse($this->repository->extendExpiration($share, new DateTimeImmutable('+30 days')), 'a revoked share is not extended (a detached instance is not refreshed)');
        $this->em->clear();
        self::assertSame($later->format('Y-m-d H:i:s'), $this->repository->find(self::ID)?->getExpiresAt()->format('Y-m-d H:i:s'));
    }

    /**
     * 1.21.0 (REQ-RUNTIME-003 rule 1): with the write unit the max reads raise and the expiration commit together; an
     * expiration refused because a later one was stored meanwhile rolls the raise back.
     */
    public function testAnExtensionIsOneUnitAndARefusedExpirationRollsTheRaiseBack(): void
    {
        $share = new SecureShare(self::ID, $this->creator);
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);
        $this->repository->persist($share);
        $this->repository->flush();
        $extender = new ShareExtender($this->repository, DefaultShareOptions::get(), null, null, null, new ShareWriteUnit($this->em));

        // Another worker stored a later expiration after this one loaded the share.
        $this->em->getConnection()->executeStatement('UPDATE yopass_secure_shares SET expires_at = ? WHERE id = ?', [(new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s'), self::ID]);
        try {
            $extender->extend($share, '24h', 3);
            self::fail('The stale extension must be refused.');
        } catch (ShareExtendException $exception) {
            self::assertSame('expiration_not_extended', $exception->errorCode);
        }
        self::assertSame(['1', '1'], array_map(strval(...), $this->em->getConnection()->fetchNumeric('SELECT max_reads, reads_left FROM yopass_secure_shares WHERE id = ?', [self::ID]) ?: []));

        $this->em->clear();
        $fresh = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $fresh);
        $extender->extend($fresh, '24h', 3);
        $this->em->clear();
        self::assertSame(3, $this->repository->find(self::ID)?->getMaxReads());
        self::assertSame(3, $this->repository->find(self::ID)?->getReadsLeft());
    }
}
