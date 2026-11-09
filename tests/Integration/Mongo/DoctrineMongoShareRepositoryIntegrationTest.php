<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Mongo;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Events;
use Doctrine\ODM\MongoDB\Mapping\Driver\AttributeDriver;
use MongoDB\Client;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\Odm\MongoUser;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function dirname;
use function extension_loaded;
use function getenv;
use function is_dir;
use function is_string;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * Round-trips every SecureShare field through a real MongoDB server (set YOPASS_TEST_MONGODB_URL to run).
 *
 * Regression for the DDD audit 2026-11-09: loading rebuilt the share through its constructor, so createdAt / revokedAt
 * came back as the load time, and saving an existing share reset its remaining reads to max reads.
 */
final class DoctrineMongoShareRepositoryIntegrationTest extends TestCase
{
    private const ID = '00000000-0000-4000-8000-0000000000d1';

    private Client $client;

    private string $database;

    private DocumentManager $dm;

    private DoctrineMongoShareRepository $repository;

    private MongoUser $creator;

    protected function setUp(): void
    {
        $url = getenv('YOPASS_TEST_MONGODB_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set YOPASS_TEST_MONGODB_URL to run the MongoDB round-trip test.');
        }

        if (!extension_loaded('mongodb')) {
            self::markTestSkipped('ext-mongodb is required.');
        }

        $this->client   = new Client($url);
        $this->database = 'yopass_test_' . bin2hex(random_bytes(4));
        $this->dm       = $this->createDocumentManager();

        $this->creator = new MongoUser('user-mongo-1');
        $this->dm->persist($this->creator);
        $this->dm->flush();

        $this->repository = new DoctrineMongoShareRepository($this->dm);
    }

    protected function tearDown(): void
    {
        if (isset($this->client, $this->database)) {
            $this->client->dropDatabase($this->database);
        }
    }

    public function testEveryFieldRoundTrips(): void
    {
        $stored = SecureShare::reconstitute(
            self::ID,
            $this->creator,
            'cipher-envelope',
            new DateTimeImmutable('2026-01-02 03:04:05.123'),
            new DateTimeImmutable('+3 days'),
            5,
            5,
            null,
            'file',
        );
        $this->save($stored);

        self::assertNotNull($this->repository->consumeReadIfAvailable(self::ID));
        $this->dm->clear();

        $loaded = $this->repository->find(self::ID);

        self::assertInstanceOf(SecureShare::class, $loaded);
        self::assertSame(self::ID, $loaded->getId());
        self::assertInstanceOf(MongoUser::class, $loaded->getCreator());
        self::assertSame('user-mongo-1', $loaded->getCreator()->getId());
        self::assertSame('cipher-envelope', $loaded->getCiphertext());
        self::assertSame('2026-01-02 03:04:05.123', $loaded->getCreatedAt()->format('Y-m-d H:i:s.v'));
        self::assertSame($stored->getExpiresAt()->format('U.v'), $loaded->getExpiresAt()->format('U.v'));
        self::assertSame(5, $loaded->getMaxReads());
        self::assertSame(4, $loaded->getReadsLeft());
        self::assertNull($loaded->getRevokedAt());
        self::assertSame('file', $loaded->getPayloadKind());
        self::assertTrue($loaded->isAvailable());

        // A later load (new request) returns the same dates, not the time of that load.
        $this->dm->clear();
        $again = $this->repository->find(self::ID);
        self::assertSame('2026-01-02 03:04:05.123', $again?->getCreatedAt()->format('Y-m-d H:i:s.v'));
    }

    public function testRevokedShareStaysRevokedWithItsOriginalDate(): void
    {
        $this->save($this->newShare(new DateTimeImmutable('+1 day'), 3));

        $share = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $share);
        (new ShareRevoker($this->repository))->revoke($share);
        $revokedAt = $share->getRevokedAt()?->format('U.v');
        self::assertNotNull($revokedAt);

        $this->dm->clear();
        $loaded = $this->repository->find(self::ID);

        self::assertSame($revokedAt, $loaded?->getRevokedAt()?->format('U.v'));
        self::assertSame(0, $loaded?->getReadsLeft());
        self::assertFalse($loaded?->isAvailable());
        self::assertNull($this->repository->consumeReadIfAvailable(self::ID));

        // Saving the loaded share again keeps the first revocation date.
        self::assertInstanceOf(SecureShare::class, $loaded);
        $this->repository->persist($loaded);
        $this->repository->flush();
        $this->dm->clear();

        self::assertSame($revokedAt, $this->repository->find(self::ID)?->getRevokedAt()?->format('U.v'));
    }

    public function testStaleShareCannotUndoARevocation(): void
    {
        $this->save($this->newShare(new DateTimeImmutable('+1 day'), 3));
        $this->dm->clear();

        $stale = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $stale);

        // Another worker revokes the share.
        $other           = $this->createDocumentManager();
        $otherRepository = new DoctrineMongoShareRepository($other);
        $fresh           = $otherRepository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $fresh);
        (new ShareRevoker($otherRepository))->revoke($fresh);

        // This worker saves its stale copy (loaded before the revocation) from a cleared manager.
        $this->dm->clear();
        $stale->extendExpiration(new DateTimeImmutable('+2 days'));
        $this->repository->persist($stale);
        $this->repository->flush();
        $this->dm->clear();

        $loaded = $this->repository->find(self::ID);
        self::assertSame($fresh->getRevokedAt()?->format('U.v'), $loaded?->getRevokedAt()?->format('U.v'));
        self::assertSame(0, $loaded?->getReadsLeft());
        self::assertNull($this->repository->consumeReadIfAvailable(self::ID));
    }

    public function testExpiredShareStaysExpired(): void
    {
        $expiresAt = new DateTimeImmutable('-1 hour');
        $this->save($this->newShare($expiresAt, 2));

        $loaded = $this->repository->find(self::ID);

        self::assertSame($expiresAt->format('U.v'), $loaded?->getExpiresAt()->format('U.v'));
        self::assertSame(2, $loaded?->getReadsLeft());
        self::assertFalse($loaded?->isAvailable());
        self::assertNull($this->repository->consumeReadIfAvailable(self::ID));
    }

    public function testExtendingAConsumedShareDoesNotGiveItsReadsBack(): void
    {
        $this->save($this->newShare(new DateTimeImmutable('+1 hour'), 1));
        self::assertNotNull($this->repository->consumeReadIfAvailable(self::ID));
        $this->dm->clear();

        $share = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $share);
        (new ShareExtender($this->repository, DefaultShareOptions::get()))->extend($share, '24h', null);
        $this->dm->clear();

        $loaded = $this->repository->find(self::ID);
        self::assertSame(0, $loaded?->getReadsLeft());
        self::assertFalse($loaded?->isAvailable());
        self::assertNull($this->repository->consumeReadIfAvailable(self::ID));

        // Raising max reads from 1 to 3 adds two reads, not three.
        self::assertInstanceOf(SecureShare::class, $loaded);
        (new ShareExtender($this->repository, DefaultShareOptions::get()))->extend($loaded, null, 3);
        $this->dm->clear();

        self::assertSame(2, $this->repository->find(self::ID)?->getReadsLeft());
    }

    private function newShare(DateTimeImmutable $expiresAt, int $maxReads): SecureShare
    {
        $share = new SecureShare(self::ID, $this->creator);
        $share->setCiphertext('cipher')->setExpiresAt($expiresAt)->setMaxReads($maxReads);

        return $share;
    }

    private function save(SecureShare $share): void
    {
        $this->repository->persist($share);
        $this->repository->flush();
        $this->dm->clear();
    }

    private function createDocumentManager(): DocumentManager
    {
        $proxyDir    = sys_get_temp_dir() . '/yopass-odm-proxies';
        $hydratorDir = sys_get_temp_dir() . '/yopass-odm-hydrators';
        foreach ([$proxyDir, $hydratorDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0o700, true);
            }
        }

        $config = new Configuration();
        $config->setProxyDir($proxyDir);
        $config->setProxyNamespace('YopassOdmProxies');
        $config->setHydratorDir($hydratorDir);
        $config->setHydratorNamespace('YopassOdmHydrators');
        $config->setAutoGenerateProxyClasses(Configuration::AUTOGENERATE_EVAL);
        $config->setAutoGenerateHydratorClasses(Configuration::AUTOGENERATE_EVAL);
        $config->setDefaultDB($this->database);
        $config->setMetadataDriverImpl(AttributeDriver::create([
            dirname(__DIR__, 3) . '/src/Document',
            dirname(__DIR__, 2) . '/Support/Odm',
        ]));

        $events = new EventManager();
        $events->addEventListener(
            Events::loadClassMetadata,
            new SecureShareDocumentMetadataListener('yopass_secure_shares', MongoUser::class),
        );

        return DocumentManager::create($this->client, $config, $events);
    }
}
