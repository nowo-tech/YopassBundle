<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * REQ-ARCH-001 rule 6 (1.19.0): shares and the access log go through the bundle's own entity manager. A share
 * create / consume / access log / extend / revoke / delete never flushes the application's pending changes, no bundle
 * write is lost (the rows are in the database before the application flushes or clears), the one-time view and expiry
 * semantics are unchanged, the rows take part in a transaction the application opened, and a closed private manager is
 * replaced without touching the application's manager.
 */
final class PrivateEntityManagerIntegrationTest extends TestCase
{
    private EntityManager $host;

    private YopassEntityManager $own;

    private DoctrineOrmShareRepository $shares;

    private DoctrineOrmShareAccessLogRepository $logs;

    private DoctrineCreatorReferenceResolver $creators;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-private-em-proxies');
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, GuardUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $this->host = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        (new SchemaTool($this->host))->createSchema($this->host->getMetadataFactory()->getAllMetadata());
        $this->host->persist(new GuardUser(7, 'alice'));
        $this->host->flush();
        $this->host->clear();

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->host);
        $this->own      = new YopassEntityManager($registry, 'default');
        $this->creators = new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, 'default', new CreatorClassPolicy(GuardUser::class, [SecurityUserDto::class]), CreatorMapping::RELATION, $this->own);
        $this->shares   = new DoctrineOrmShareRepository($this->host, null, $registry, 'default', $this->creators, $this->own);
        $this->logs     = new DoctrineOrmShareAccessLogRepository($this->host, $registry, 'default', $this->own);
    }

    public function testShareWritesNeverFlushTheApplicationsPendingChanges(): void
    {
        // The application has a pending update and a pending insert in its own unit of work.
        $alice = $this->host->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $alice->name = 'alice-renamed';
        $bob         = new GuardUser(8, 'bob');
        $this->host->persist($bob);

        // Every bundle write: create (with the application-managed user as creator), consume, access log, extend,
        // revoke, delete.
        $share = $this->creator()->create($alice, $this->data(3));
        self::assertSame('7', $share->getCreatorId());
        self::assertSame('ok', (new ShareRetriever($this->shares))->consume($share->getId())['status']);
        $stored = $this->shares->find($share->getId());
        self::assertInstanceOf(SecureShare::class, $stored);
        (new ShareAccessLogger($this->logs, true))->logSuccessfulConsume($stored, Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.5']));
        (new ShareExtender($this->shares, DefaultShareOptions::get()))->extend($stored, null, 10);
        $second = $this->creator()->create($alice, $this->data(1));
        (new ShareRevoker($this->shares))->revoke($second);
        $third = $this->creator()->create(new SecurityUserDto(7, 'alice'), $this->data(1));
        (new ShareDeleter($this->shares))->delete($third);

        // Nothing of the application reached the database...
        self::assertSame('alice', $this->db('SELECT name FROM guard_users WHERE id = 7'));
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM guard_users WHERE id = 8'));
        // ...and its unit of work still holds both changes.
        self::assertTrue($this->host->getUnitOfWork()->isScheduledForInsert($bob));
        $this->host->getUnitOfWork()->computeChangeSets();
        self::assertSame(['alice', 'alice-renamed'], $this->host->getUnitOfWork()->getEntityChangeSet($alice)['name'] ?? null);
        self::assertFalse($this->host->contains($stored), 'Shares are never in the application unit of work');

        // No bundle write is lost: every row is stored before the application flushes or clears.
        self::assertSame(2, (int) $this->db('SELECT COUNT(*) FROM yopass_secure_shares'));
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM yopass_share_access_logs'));
        self::assertSame(9, (int) $this->db('SELECT reads_left FROM yopass_secure_shares WHERE id = ?', [$share->getId()]));
        self::assertNotNull($this->db('SELECT revoked_at FROM yopass_secure_shares WHERE id = ?', [$second->getId()]));

        $this->host->clear();
        self::assertSame(2, (int) $this->db('SELECT COUNT(*) FROM yopass_secure_shares'));

        // The application's own flush still writes its changes, and only its changes.
        $alice = $this->host->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $alice);
        $alice->name = 'alice-renamed';
        $this->host->persist(new GuardUser(8, 'bob'));
        $this->host->flush();
        self::assertSame('alice-renamed', $this->db('SELECT name FROM guard_users WHERE id = 7'));
        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM guard_users WHERE id = 8'));
        self::assertSame(2, (int) $this->db('SELECT COUNT(*) FROM yopass_secure_shares'));
    }

    public function testWithoutThePrivateManagerTheApplicationsPendingChangesWereFlushed(): void
    {
        // The pre-1.19 behaviour (`database.private_entity_manager: false`), kept for comparison.
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->host);
        $creators = new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, 'default', new CreatorClassPolicy(GuardUser::class));
        $shares   = new DoctrineOrmShareRepository($this->host, null, $registry, 'default', $creators);
        $this->host->persist(new GuardUser(8, 'bob'));

        (new ShareCreator($shares, DefaultShareOptions::get()['expiration_options'], null, null, $creators))
            ->create($this->host->getReference(GuardUser::class, 7), $this->data(1));

        self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM guard_users WHERE id = 8'));
    }

    public function testOneTimeViewAndExpiryAreUnchanged(): void
    {
        $once = $this->creator()->create(new SecurityUserDto(7, 'alice'), $this->data(1));
        $gone = $this->creator()->create(new SecurityUserDto(7, 'alice'), $this->data(2));
        $this->host->getConnection()->executeStatement('UPDATE yopass_secure_shares SET expires_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $gone->getId()]);
        $retriever = new ShareRetriever($this->shares);

        self::assertSame('ok', $retriever->consume($once->getId())['status']);
        self::assertSame('consumed', $retriever->consume($once->getId())['status']);
        self::assertSame('expired', $retriever->consume($gone->getId())['status']);
        self::assertSame('not_found', $retriever->consume('00000000-0000-4000-8000-000000000000')['status']);

        // Another request: the identity map is emptied at the boundary, and the row is read again.
        $this->own->clear();
        self::assertSame('consumed', $retriever->consume($once->getId())['status']);
        self::assertSame(0, $this->shares->find($once->getId())?->getReadsLeft());
    }

    public function testBundleRowsTakePartInATransactionTheApplicationOpened(): void
    {
        try {
            $this->host->wrapInTransaction(function (): never {
                $this->host->persist(new GuardUser(9, 'carol'));
                $this->creator()->create(new SecurityUserDto(7, 'alice'), $this->data(1));
                self::assertSame(1, (int) $this->db('SELECT COUNT(*) FROM yopass_secure_shares'));

                throw new RuntimeException('application failure');
            });
            self::fail('Expected the application failure');
        } catch (RuntimeException $exception) {
            self::assertSame('application failure', $exception->getMessage());
        }

        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM yopass_secure_shares'), 'Rolled back with the application');
        self::assertSame(0, (int) $this->db('SELECT COUNT(*) FROM guard_users WHERE id = 9'));
    }

    public function testAFailedWriteClosesOnlyThePrivateManager(): void
    {
        $share = $this->creator()->create(new SecurityUserDto(7, 'alice'), $this->data(1));
        $first = $this->own->get();

        $duplicate = new SecureShare($share->getId(), $this->own->get()->getReference(GuardUser::class, 7));
        $duplicate->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'));
        $this->own->clear();
        try {
            $this->shares->persist($duplicate);
            $this->shares->flush();
            self::fail('Expected a unique violation');
        } catch (Throwable) {
        }

        self::assertFalse($first->isOpen());
        self::assertTrue($this->host->isOpen(), 'The application manager stays open');
        self::assertNotSame($first, $this->own->get(), 'Replaced on the next call');
        self::assertSame('ok', (new ShareRetriever($this->shares))->consume($share->getId())['status']);
    }

    public function testCreatorReferencesLiveInThePrivateManager(): void
    {
        $hostUser = $this->host->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $hostUser);
        $reference = $this->creators->reference($hostUser);
        self::assertNotSame($hostUser, $reference);
        self::assertTrue($this->own->get()->contains($reference));
        self::assertFalse($this->host->contains($reference));

        $ownUser = $this->own->get()->find(GuardUser::class, 7);
        self::assertInstanceOf(GuardUser::class, $ownUser);
        self::assertSame($ownUser, $this->creators->reference($ownUser), 'Already managed by the private manager');

        $dto = $this->creators->reference(new SecurityUserDto(7, 'alice'));
        self::assertTrue($this->own->get()->contains($dto), 'accepted_user_classes references are built there too');
    }

    private function creator(): ShareCreator
    {
        return new ShareCreator($this->shares, DefaultShareOptions::get()['expiration_options'], null, null, $this->creators);
    }

    private function data(int $maxReads): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = $maxReads;

        return $data;
    }

    /**
     * @param list<mixed> $params
     */
    private function db(string $sql, array $params = []): mixed
    {
        return $this->host->getConnection()->fetchOne($sql, $params);
    }
}
