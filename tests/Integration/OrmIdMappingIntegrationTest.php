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
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareLister;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\Orm\GuardAdmin;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;
use function dirname;
use function extension_loaded;
use function method_exists;
use function str_replace;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Strict DDD host (1.18.0, `user_mapping: id`): only the bundle entities are mapped (no host user entity at all), the
 * security user is a plain read model with a UUID id, and the share stores that id in a string column without any
 * foreign key. Creating, listing, owner checks, id-typed queries and the erasure by id work; another identity domain
 * fails closed.
 */
final class OrmIdMappingIntegrationTest extends TestCase
{
    private const ALICE = '0b5f2c1e-1111-4a1a-8a1a-000000000001';

    private EntityManager $em;

    private DoctrineOrmShareRepository $repository;

    private DoctrineCreatorReferenceResolver $creators;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-id-proxies');
        $this->enableLazyObjects($config);

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new SecureShareMetadataListener('yopass_secure_shares', SecurityUserDto::class, 'yopass_share_access_logs', CreatorMapping::ID));

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->creators   = new DoctrineCreatorReferenceResolver(SecurityUserDto::class, null, null, new CreatorClassPolicy(SecurityUserDto::class), CreatorMapping::ID);
        $this->repository = new DoctrineOrmShareRepository($this->em, null, null, null, $this->creators);
    }

    public function testSchemaStoresTheCreatorIdWithoutForeignKey(): void
    {
        $table = $this->em->getConnection()->createSchemaManager()->introspectTable('yopass_secure_shares');

        self::assertSame(CreatorMapping::ID_LENGTH, $table->getColumn('creator_id')->getLength());
        self::assertTrue($table->getColumn('creator_id')->getNotnull());
        self::assertCount(0, $table->getForeignKeys());
    }

    public function testPlainSecurityUserCreatesListsAndOwnsShares(): void
    {
        $alice      = new SecurityUserDto(self::ALICE, 'alice');
        $dispatcher = new EventDispatcher();
        $ids        = [];
        $dispatcher->addListener(YopassEvents::SHARE_CREATED, static function (ShareCreatedEvent $event) use (&$ids): void {
            $ids[] = $event->getCreatorId();
        });

        $share = (new ShareCreator($this->repository, DefaultShareOptions::get()['expiration_options'], null, $dispatcher, $this->creators))->create($alice, $this->data());
        self::assertSame([self::ALICE], $ids);
        $this->em->clear();

        $stored = $this->repository->find($share->getId());
        self::assertInstanceOf(SecureShare::class, $stored);
        self::assertEquals(new CreatorId(self::ALICE), $stored->getCreator());
        self::assertSame(self::ALICE, $stored->getCreatorId());

        $guard = new ShareAccessGuard(new EventDispatcher(), new CreatorClassPolicy(SecurityUserDto::class));
        self::assertTrue($guard->canManage($alice, $stored, ShareAccessAction::Delete));
        self::assertFalse($guard->canManage(new SecurityUserDto('0b5f2c1e-2222-4a1a-8a1a-000000000002'), $stored, ShareAccessAction::Delete));

        $list = (new ShareLister($this->repository, new EventDispatcher(), SecurityUserDto::class, $this->creators))->list($alice, 10, 1);
        self::assertSame(1, $list['total']);
        self::assertSame($share->getId(), $list['shares'][0]->getId());
        self::assertSame(1, $this->repository->countByCreator($stored->getCreator()));
        self::assertCount(1, $this->repository->findByCreatorPaginated($alice, 10, 0));
        self::assertSame(0, $this->repository->removeByCreatorOlderThan($alice, new DateTimeImmutable('-1 day')));
    }

    public function testIdTypedQueriesAndErasureById(): void
    {
        $creator = new ShareCreator($this->repository, DefaultShareOptions::get()['expiration_options'], null, null, $this->creators);
        $creator->create(new SecurityUserDto(self::ALICE), $this->data());
        $creator->create(new SecurityUserDto(self::ALICE), $this->data());
        $creator->create(new SecurityUserDto('someone-else'), $this->data());
        $this->em->clear();

        self::assertCount(2, $this->repository->findByCreatorId(self::ALICE));
        self::assertSame(2, $this->repository->countByCreatorId(self::ALICE));
        self::assertSame(2, $this->repository->removeAllByCreatorId(self::ALICE));
        self::assertSame(0, $this->repository->countByCreatorId(self::ALICE));
        self::assertSame(1, $this->repository->countByCreatorId('someone-else'));
    }

    public function testAnotherIdentityDomainFailsClosed(): void
    {
        $creator = new ShareCreator($this->repository, DefaultShareOptions::get()['expiration_options'], null, null, $this->creators);
        $creator->create(new SecurityUserDto('7'), $this->data());

        try {
            $this->repository->countByCreator(new GuardAdmin(7));
            self::fail('Counting with a foreign identity must be refused.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AccessDeniedException::class);
        $creator->create(new GuardAdmin(7), $this->data());
    }

    /**
     * REQ-SEC-006 item 6 (1.20.4): a page holds the creator's own shares even when the column folds case (here SQLite
     * NOCASE, like a host's MySQL `*_ci` column) and newer shares of a look-alike creator come first: the exact match
     * happens before the page is cut. Before 1.20.4 the first page of `abc` was empty.
     */
    public function testPagesMatchTheCreatorExactlyBeforeTheLimit(): void
    {
        $connection = $this->em->getConnection();
        $tool       = new SchemaTool($this->em);
        $metadata   = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        foreach ($tool->getCreateSchemaSql($metadata) as $sql) {
            $connection->executeStatement(str_replace('creator_id VARCHAR(128) NOT NULL', 'creator_id VARCHAR(128) NOT NULL COLLATE NOCASE', $sql));
        }

        $creator = new ShareCreator($this->repository, DefaultShareOptions::get()['expiration_options'], null, null, $this->creators);
        $own     = [];
        foreach (['abc', 'AbC', 'abc', 'AbC', 'ABC', 'abc'] as $minute => $id) {
            $share = $creator->create(new SecurityUserDto($id), $this->data());
            $connection->executeStatement('UPDATE yopass_secure_shares SET created_at = ? WHERE id = ?', ['2026-11-13 10:0' . $minute . ':00', $share->getId()]);
            if ($id === 'abc') {
                $own[] = $share->getId();
            }
        }
        $this->em->clear();
        self::assertSame(6, (int) $connection->fetchOne("SELECT COUNT(*) FROM yopass_secure_shares WHERE creator_id = 'abc'"), 'The collation folds case.');

        $page = static fn (array $shares): array => array_map(static fn (SecureShare $s): string => $s->getId(), $shares);
        self::assertSame([$own[2], $own[1]], $page($this->repository->findByCreatorPaginated(new SecurityUserDto('abc'), 2, 0)));
        self::assertSame([$own[0]], $page($this->repository->findByCreatorPaginated(new SecurityUserDto('abc'), 2, 2)));
        self::assertSame([], $this->repository->findByCreatorPaginated(new SecurityUserDto('abc'), 2, 4));
        self::assertSame([], $this->repository->findByCreatorPaginated(new SecurityUserDto('Abc'), 2, 0), 'nobody is Abc');
        self::assertCount(2, $this->repository->findByCreatorPaginated(new SecurityUserDto('AbC'), 5, 0));
        self::assertSame(3, $this->repository->countByCreatorId('abc'));
    }

    private function data(): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = 3;

        return $data;
    }

    private function enableLazyObjects(Configuration $config): void
    {
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
    }
}
