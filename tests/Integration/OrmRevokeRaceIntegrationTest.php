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
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareRevokedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareCommands;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareQuery;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Share\ShareCommandException;
use Nowo\YopassBundle\Share\ShareView;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use Nowo\YopassBundle\Tests\Support\RacingShareRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 2.1.1 (audit YD1-L1, YD1-L3) on the Doctrine ORM driver (SQLite): the revoke is one conditional write, so a revoke that
 * loses to another revoke or to a delete writes nothing and dispatches nothing, and an extension racing a delete is
 * refused with `not_found`. A creator id that cannot name an integer-keyed `user_class` user is refused with
 * `ShareCommandException` (create) or counted as no shares (`deleteAllOf()`), never an AccessDeniedException. The same
 * races between two connections run on MySQL, MariaDB and PostgreSQL in {@see Database\ConcurrencyDatabaseTest}.
 */
final class OrmRevokeRaceIntegrationTest extends TestCase
{
    private const ID = '00000000-0000-4000-8000-0000000000c1';

    private EntityManager $em;

    private DoctrineOrmShareRepository $repository;

    private RacingShareRepository $racing;

    private DoctrineCreatorReferenceResolver $creators;

    private EventDispatcher $dispatcher;

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-revoke-race-proxies');
        $this->enableLazyObjects($config);

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, GuardUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->em->persist(new GuardUser(7, 'alice'));
        $this->em->flush();
        $this->em->clear();

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $this->creators   = new DoctrineCreatorReferenceResolver(GuardUser::class, $registry, 'default', new CreatorClassPolicy(GuardUser::class));
        $this->repository = new DoctrineOrmShareRepository($this->em, null, null, null, $this->creators);
        $this->racing     = new RacingShareRepository($this->repository);

        $this->dispatcher = new EventDispatcher();
        foreach ([YopassEvents::SHARE_CREATED, YopassEvents::SHARE_REVOKED, YopassEvents::SHARE_EXTENDED, YopassEvents::SHARE_DELETED, YopassEvents::SHARES_BULK_DELETED] as $name) {
            $this->dispatcher->addListener($name, function () use ($name): void {
                $this->events[] = $name;
            });
        }
    }

    public function testOnlyTheFirstOfTwoRevokesWritesAndDispatches(): void
    {
        $this->saveShare();
        $first   = $this->repository->find(self::ID);
        $revoker = new ShareRevoker($this->repository, $this->dispatcher);
        self::assertInstanceOf(SecureShare::class, $first);

        self::assertTrue($revoker->revoke($first));
        self::assertNotNull($first->getRevokedAt(), 'The managed share is refreshed after the write');
        self::assertSame(0, $first->getReadsLeft());
        $stored = $this->storedRevokedAt();
        self::assertNotNull($stored);

        // A second revoke of a share that is stale in memory (another worker loaded it before the first revoke).
        $this->em->clear();
        $stale = SecureShare::reconstitute(self::ID, $this->em->getReference(GuardUser::class, 7), 'cipher', new DateTimeImmutable('-1 minute'), new DateTimeImmutable('+1 hour'), 3, 3, null, 'text');
        self::assertFalse($revoker->revoke($stale));
        self::assertNull($stale->getRevokedAt(), 'Nothing written, nothing refreshed');

        self::assertSame([YopassEvents::SHARE_REVOKED], $this->events, 'One event for two revokes');
        self::assertSame($stored, $this->storedRevokedAt(), 'The first revocation date stays');
    }

    public function testARevokeOfAShareDeletedMeanwhileWritesAndDispatchesNothing(): void
    {
        $this->saveShare();
        $loaded = $this->repository->find(self::ID);
        self::assertInstanceOf(SecureShare::class, $loaded);
        $this->deleteRow(self::ID);

        self::assertFalse((new ShareRevoker($this->repository, $this->dispatcher))->revoke($loaded));
        self::assertSame([], $this->events);
        self::assertSame(0, $this->rows());
    }

    public function testTheCommandsRevokeOfAShareDeletedMeanwhileAnswersNull(): void
    {
        $this->saveShare();
        $this->racing->beforeWrite = $this->deleteRow(...);

        self::assertNull($this->commands()->revoke(self::ID, 'admin'));
        self::assertSame([], $this->events);
        self::assertSame(0, $this->rows());
    }

    public function testTheCommandsRevokeThatLosesToAnotherRevokeAnswersTheStoredStateWithoutAnEvent(): void
    {
        $this->saveShare();
        $this->racing->beforeWrite = function (string $id): void {
            $this->em->getConnection()->executeStatement('UPDATE yopass_secure_shares SET revoked_at = ?, reads_left = 0 WHERE id = ?', ['2026-01-01 00:00:00', $id]);
        };

        $view = $this->commands()->revoke(self::ID, 'admin');
        self::assertInstanceOf(ShareView::class, $view);
        self::assertSame(ShareView::STATE_REVOKED, $view->state);
        self::assertSame([], $this->events, 'The other revoke dispatched its own event');
        self::assertSame('2026-01-01 00:00:00', $this->storedRevokedAt());
    }

    public function testTheCommandsRevokeDispatchesOnceAndARepeatChangesNothing(): void
    {
        $this->saveShare();
        $commands = $this->commands();

        self::assertSame(ShareView::STATE_REVOKED, $commands->revoke(self::ID, 'admin')?->state);
        self::assertSame(ShareView::STATE_REVOKED, $commands->revoke(self::ID, 'admin')?->state);
        self::assertSame([YopassEvents::SHARE_REVOKED], $this->events);
    }

    /**
     * @return iterable<string, array{?string, ?int}>
     */
    public static function extensions(): iterable
    {
        yield 'max reads raise' => [null, 10];
        yield 'expiration' => ['7d', null];
        yield 'both' => ['7d', 10];
    }

    #[DataProvider('extensions')]
    public function testAnExtensionRacingADeleteIsRefusedAsNotFoundWithoutAnEvent(?string $expiresIn, ?int $maxReads): void
    {
        $this->saveShare();
        $this->racing->beforeWrite = $this->deleteRow(...);

        try {
            $this->commands()->extend(self::ID, $expiresIn, $maxReads, 'admin');
            self::fail('Expected ShareExtendException');
        } catch (ShareExtendException $exception) {
            self::assertSame('not_found', $exception->errorCode);
        }
        self::assertSame([], $this->events);
        self::assertSame(0, $this->rows());
    }

    public function testAnExtensionRacingARevokeIsStillRefusedAsRevoked(): void
    {
        $this->saveShare();
        $this->racing->beforeWrite = function (string $id): void {
            $this->em->getConnection()->executeStatement('UPDATE yopass_secure_shares SET revoked_at = ?, reads_left = 0 WHERE id = ?', ['2026-01-01 00:00:00', $id]);
        };

        try {
            $this->commands()->extend(self::ID, '7d', null, 'admin');
            self::fail('Expected ShareExtendException');
        } catch (ShareExtendException $exception) {
            self::assertSame('revoked', $exception->errorCode);
        }
        self::assertSame([], $this->events);
    }

    public function testACreatorIdThatCannotNameAnIntegerKeyedUserIsRefusedByTheCommands(): void
    {
        $commands = $this->commands();
        foreach (['abc', '07', '7.0'] as $id) {
            try {
                $commands->create(new CreatorId($id), $this->data());
                self::fail('Expected ShareCommandException for ' . $id);
            } catch (ShareCommandException $exception) {
                self::assertSame(ShareCommandException::INVALID_CREATOR, $exception->errorCode);
            }
            self::assertSame(0, $commands->deleteAllOf(new CreatorId($id), 'admin'), 'An unusable id has no shares, as an unknown id');
        }
        self::assertSame(0, $this->rows());
        self::assertSame([], $this->events);

        $view = $commands->create(new CreatorId('7'), $this->data());
        self::assertSame('7', $view->creatorId);
        self::assertSame(1, $commands->deleteAllOf(new CreatorId('7'), 'admin'));
        self::assertSame([YopassEvents::SHARE_CREATED, YopassEvents::SHARES_BULK_DELETED], $this->events);
    }

    public function testTheRevokedEventCarriesTheStoredRevocation(): void
    {
        $this->saveShare();
        $events = [];
        $this->dispatcher->addListener(YopassEvents::SHARE_REVOKED, static function (ShareRevokedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $this->commands()->revoke(self::ID, 'admin');
        self::assertCount(1, $events);
        self::assertSame($this->storedRevokedAt(), $events[0]->getRevokedAt()->format('Y-m-d H:i:s'));
        self::assertSame('admin', $events[0]->getRevokedById());
    }

    private function commands(): ShareCommands
    {
        $options = DefaultShareOptions::get();

        return new ShareCommands(
            $this->repository,
            new ShareCreator($this->repository, $options['expiration_options'], null, $this->dispatcher, $this->creators),
            new ShareRevoker($this->racing, $this->dispatcher),
            new ShareExtender($this->racing, $options, null, $this->dispatcher),
            new ShareDeleter($this->repository, $this->dispatcher, GuardUser::class, null, $this->creators),
            new ShareQuery($this->repository, null, $this->creators),
            $options,
            700_000,
            false,
            null,
            $this->dispatcher,
            $this->creators,
        );
    }

    private function saveShare(): void
    {
        $share = new SecureShare(self::ID, $this->em->getReference(GuardUser::class, 7));
        $share->setCiphertext('cipher')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(3);
        $this->repository->persist($share);
        $this->repository->flush();
        $this->em->clear();
    }

    private function deleteRow(string $id): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM yopass_secure_shares WHERE id = ?', [$id]);
    }

    private function rows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares');
    }

    private function storedRevokedAt(): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT revoked_at FROM yopass_secure_shares WHERE id = ?', [self::ID]);

        return $value === false || $value === null ? null : (string) $value;
    }

    private function data(): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';

        return $data;
    }

    private function enableLazyObjects(Configuration $config): void
    {
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
    }
}
