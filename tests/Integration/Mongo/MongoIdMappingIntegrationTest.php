<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Mongo;

use Doctrine\Common\EventManager;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Events;
use Doctrine\ODM\MongoDB\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\ManagerRegistry;
use MongoDB\Client;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
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
