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
use LogicException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\ShareDeleteAllEvent;
use Nowo\YopassBundle\Event\ShareListQueryEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareLister;
use Nowo\YopassBundle\Tests\Support\Orm\GuardTenant;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

use function dirname;
use function extension_loaded;
use function method_exists;
use function sprintf;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Regression test for pre-release audit EL-L1, built from its PoC: on the default ORM repository a tenant entity set
 * as delete-all subject was bound by its id, so Alice (user 1) deleting "her tenant 7" removed the shares of Bob
 * (user 7) and got a success flash. A non-user subject must now be refused before anything is removed.
 */
final class CreatorSubjectGuardIntegrationTest extends TestCase
{
    private EntityManager $em;

    private DoctrineOrmShareRepository $repository;

    private GuardUser $alice;

    private GuardUser $bob;

    private GuardTenant $tenant;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $paths  = [__DIR__ . '/../Support/Orm', dirname(__DIR__, 2) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-guard-proxies');
        $this->enableLazyObjects($config);

        $resolver = new ResolveTargetEntityListener();
        $resolver->addResolveTargetEntity(UserInterface::class, GuardUser::class, []);
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, $resolver);

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config, $events);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->alice  = new GuardUser(1, 'alice');
        $this->bob    = new GuardUser(7, 'bob');
        $this->tenant = new GuardTenant(7); // tenant id collides with Bob's user id
        $this->em->persist($this->alice);
        $this->em->persist($this->bob);
        $this->em->persist($this->tenant);
        foreach ([$this->alice, $this->bob, $this->bob] as $i => $creator) {
            $share = new SecureShare(sprintf('00000000-0000-4000-8000-%012d', $i + 1), $creator);
            $share->setCiphertext('PLACEHOLDER-CIPHERTEXT')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);
            $this->em->persist($share);
        }
        $this->em->flush();

        $this->repository = new DoctrineOrmShareRepository($this->em);
    }

    public function testDeleteAllWithTenantSubjectDeletesNothingAndThrows(): void
    {
        $bulk       = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, fn (ShareDeleteAllEvent $event) => $event->setSubject($this->tenant));
        $dispatcher->addListener(YopassEvents::SHARES_BULK_DELETED, static function () use (&$bulk): void {
            ++$bulk;
        });

        try {
            (new ShareDeleter($this->repository, $dispatcher, GuardUser::class))->deleteAll($this->alice);
            self::fail('A tenant subject must be refused.');
        } catch (LogicException $exception) {
            self::assertStringContainsString(GuardTenant::class, $exception->getMessage());
            self::assertStringNotContainsString('PLACEHOLDER', $exception->getMessage());
        }

        self::assertSame(1, $this->repository->countByCreator($this->alice));
        self::assertSame(2, $this->repository->countByCreator($this->bob), "Bob's shares must survive");
        self::assertSame(0, $bulk);
    }

    public function testDeleteAllWithoutConfiguredCreatorClassStillRefusesANonUserSubject(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, fn (ShareDeleteAllEvent $event) => $event->setSubject($this->tenant));

        $this->expectException(LogicException::class);

        try {
            (new ShareDeleter($this->repository, $dispatcher))->deleteAll($this->alice);
        } finally {
            self::assertSame(2, $this->repository->countByCreator($this->bob));
        }
    }

    public function testDeleteAllDefaultsToTheViewerOnly(): void
    {
        $removed = (new ShareDeleter($this->repository, new EventDispatcher(), GuardUser::class))->deleteAll($this->alice);

        self::assertSame(1, $removed);
        self::assertSame(0, $this->repository->countByCreator($this->alice));
        self::assertSame(2, $this->repository->countByCreator($this->bob));
    }

    public function testTenantScopeHandledByTheListenerIsAccepted(): void
    {
        $bulk       = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, function (ShareDeleteAllEvent $event): void {
            $event->setSubject($this->tenant);
            $event->markHandled(0); // host query found nothing of Alice's in the tenant
        });
        $dispatcher->addListener(YopassEvents::SHARES_BULK_DELETED, static function (ShareBulkDeletedEvent $event) use (&$bulk): void {
            $bulk[] = $event;
        });

        self::assertSame(0, (new ShareDeleter($this->repository, $dispatcher, GuardUser::class))->deleteAll($this->alice));
        self::assertSame(2, $this->repository->countByCreator($this->bob));
        self::assertSame([], $bulk);
    }

    public function testListWithTenantSubjectThrowsInsteadOfListingBobsShares(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, fn (ShareListQueryEvent $event) => $event->setListSubject($this->tenant));

        $this->expectException(LogicException::class);
        (new ShareLister($this->repository, $dispatcher, GuardUser::class))->list($this->alice, 10, 1);
    }

    public function testListWithAnotherUserSubjectIsAccepted(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, fn (ShareListQueryEvent $event) => $event->setListSubject($this->bob));

        $result = (new ShareLister($this->repository, $dispatcher, GuardUser::class))->list($this->alice, 0, 1);

        self::assertSame(2, $result['total']);
    }

    private function enableLazyObjects(Configuration $config): void
    {
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
    }
}
