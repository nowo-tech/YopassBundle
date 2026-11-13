<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Mongo;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Events;
use Doctrine\ODM\MongoDB\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\ManagerRegistry;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function dirname;
use function extension_loaded;
use function getenv;
use function is_dir;
use function is_string;
use function json_encode;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * Strict DDD host on MongoDB (1.18.0, `user_mapping: id`): no user document mapped, the creator is a plain string
 * field holding the user's UUID (set YOPASS_TEST_MONGODB_URL to run).
 */
final class MongoIdMappingIntegrationTest extends TestCase
{
    private const ALICE = '0b5f2c1e-1111-4a1a-8a1a-000000000001';

    private Client $client;

    private string $database;

    private DocumentManager $dm;

    private DoctrineMongoShareRepository $repository;

    private DoctrineCreatorReferenceResolver $creators;

    protected function setUp(): void
    {
        $url = getenv('YOPASS_TEST_MONGODB_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set YOPASS_TEST_MONGODB_URL to run the MongoDB id-mapping test.');
        }

        if (!extension_loaded('mongodb')) {
            self::markTestSkipped('ext-mongodb is required.');
        }

        $this->client   = new Client($url);
        $this->database = 'yopass_test_' . bin2hex(random_bytes(4));
        $this->dm       = $this->createDocumentManager();
        $registry       = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->dm);
        $this->creators   = new DoctrineCreatorReferenceResolver(SecurityUserDto::class, $registry, 'default', new CreatorClassPolicy(SecurityUserDto::class), CreatorMapping::ID);
        $this->repository = new DoctrineMongoShareRepository($this->dm, null, null, null, $this->creators);
    }

    protected function tearDown(): void
    {
        if (isset($this->client, $this->database)) {
            $this->client->dropDatabase($this->database);
        }
    }

    public function testCreatorIdRoundTripsAsAPlainString(): void
    {
        $alice   = new SecurityUserDto(self::ALICE, 'alice');
        $creator = new ShareCreator($this->repository, DefaultShareOptions::get()['expiration_options'], null, null, $this->creators);
        $share   = $creator->create($alice, $this->data());
        $creator->create(new SecurityUserDto('someone-else'), $this->data());
        $this->dm->clear();

        $raw = $this->client->selectCollection($this->database, 'yopass_secure_shares')->findOne(['_id' => $share->getId()]);
        self::assertNotNull($raw);
        self::assertSame(self::ALICE, $raw['creator']);

        $loaded = $this->repository->find($share->getId());
        self::assertEquals(new CreatorId(self::ALICE), $loaded?->getCreator());
        self::assertSame(self::ALICE, $loaded?->getCreatorId());
        self::assertSame(1, $this->repository->countByCreator($alice));
        self::assertCount(1, $this->repository->findByCreatorPaginated($alice, 10, 0));
        self::assertCount(1, $this->repository->findByCreatorId(self::ALICE));
        self::assertSame(1, $this->repository->countByCreatorId(self::ALICE));
        self::assertSame(1, $this->repository->removeAllByCreatorId(self::ALICE));
        self::assertSame(0, $this->repository->countByCreatorId(self::ALICE));
        self::assertSame(1, $this->repository->countByCreatorId('someone-else'));
    }

    public function testDeletionsByCreatorIncludeSharesStoredBeforeTheSwitch(): void
    {
        $collection = $this->client->selectCollection($this->database, 'yopass_secure_shares');
        $objectId   = new ObjectId();
        $old        = new UTCDateTime(new DateTimeImmutable('-10 days'));
        foreach ([['legacy-int', 5], ['legacy-oid', $objectId], ['current', '5'], ['other', '6']] as [$id, $creator]) {
            $collection->insertOne(['_id' => $id, 'creator' => $creator, 'ciphertext' => 'c', 'expiresAt' => $old, 'createdAt' => $old, 'maxReads' => 1, 'readsLeft' => 1, 'revokedAt' => null, 'payloadKind' => 'text']);
        }

        self::assertSame(2, $this->repository->removeAllByCreatorId('5'), 'The int-stored creator 5 and the string "5".');
        self::assertSame(1, $this->repository->removeByCreatorOlderThan(new SecurityUserDto((string) $objectId), new DateTimeImmutable('-1 day')));
        self::assertSame(['other'], array_map(static fn (array|object $doc): mixed => ((array) $doc)['_id'], $collection->find([], ['typeMap' => ['root' => 'array']])->toArray()));
        self::assertSame(1, $this->repository->removeAllByCreator(new SecurityUserDto('6')));
        self::assertSame(['abc'], DoctrineMongoShareRepository::storedCreatorValues('abc'));
        self::assertSame(['7', 7], DoctrineMongoShareRepository::storedCreatorValues('7'));
        // REQ-SEC-006 item 5 (1.20.4): `$` accepted a trailing newline; an ObjectId is matched by its own lower-case form
        self::assertSame(["7\n"], DoctrineMongoShareRepository::storedCreatorValues("7\n"));
        self::assertSame(['65A1B2C3D4E5F60718293A4B'], DoctrineMongoShareRepository::storedCreatorValues('65A1B2C3D4E5F60718293A4B'));
        self::assertCount(2, DoctrineMongoShareRepository::storedCreatorValues('65a1b2c3d4e5f60718293a4b'));
    }

    /**
     * REQ-SEC-006 (1.20.4): a host whose collection has a case-insensitive default collation still gets exact creator
     * matches (lists, pages before `limit`, counts, deletions), and look-alikes of `abc` never reach its shares.
     */
    public function testCreatorMatchingIsExactUnderACaseInsensitiveCollectionCollation(): void
    {
        $this->client->selectDatabase($this->database)->createCollection('yopass_secure_shares', ['collation' => ['locale' => 'en', 'strength' => 2]]);
        $collection = $this->client->selectCollection($this->database, 'yopass_secure_shares');
        $future     = new UTCDateTime(new DateTimeImmutable('+1 day'));
        foreach ([['own', 'abc', '-1 hour'], ['upper-1', 'AbC', '-3 minutes'], ['upper-2', 'ABC', '-2 minutes'], ['accent', 'ábc', '-1 minute']] as [$id, $creator, $at]) {
            $collection->insertOne(['_id' => $id, 'creator' => $creator, 'ciphertext' => 'c', 'expiresAt' => $future, 'createdAt' => new UTCDateTime(new DateTimeImmutable($at)), 'maxReads' => 1, 'readsLeft' => 1, 'revokedAt' => null, 'payloadKind' => 'text']);
        }
        self::assertSame(3, $collection->countDocuments(['creator' => 'abc']), 'The collection folds case.');

        $abc = new SecurityUserDto('abc');
        self::assertSame(['own'], array_map(static fn (SecureShare $s): string => $s->getId(), $this->repository->findByCreatorPaginated($abc, 1, 0)));
        self::assertSame(['own'], array_map(static fn (SecureShare $s): string => $s->getId(), $this->repository->findByCreator($abc)));
        self::assertSame(1, $this->repository->countByCreator($abc));
        foreach (['Abc', 'abc ', "abc\u{00A0}", "abc\u{200B}"] as $lookAlike) {
            self::assertSame([], $this->repository->findByCreatorPaginated(CreatorId::wrap($lookAlike), 10, 0), (string) json_encode($lookAlike));
        }
        self::assertSame(1, $this->repository->removeAllByCreatorId('abc'));
        self::assertSame(3, $collection->countDocuments([]), 'The look-alike creators keep their shares.');
    }

    private function data(): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = 2;

        return $data;
    }

    private function createDocumentManager(): DocumentManager
    {
        $proxyDir    = sys_get_temp_dir() . '/yopass-odm-id-proxies';
        $hydratorDir = sys_get_temp_dir() . '/yopass-odm-id-hydrators';
        foreach ([$proxyDir, $hydratorDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0o700, true);
            }
        }

        $config = new Configuration();
        $config->setProxyDir($proxyDir);
        $config->setProxyNamespace('YopassOdmIdProxies');
        $config->setHydratorDir($hydratorDir);
        $config->setHydratorNamespace('YopassOdmIdHydrators');
        $config->setAutoGenerateProxyClasses(Configuration::AUTOGENERATE_EVAL);
        $config->setAutoGenerateHydratorClasses(Configuration::AUTOGENERATE_EVAL);
        $config->setDefaultDB($this->database);
        $config->setMetadataDriverImpl(AttributeDriver::create([dirname(__DIR__, 3) . '/src/Document']));

        $events = new EventManager();
        $events->addEventListener(
            Events::loadClassMetadata,
            new SecureShareDocumentMetadataListener('yopass_secure_shares', SecurityUserDto::class, CreatorMapping::ID),
        );

        return DocumentManager::create($this->client, $config, $events);
    }
}
