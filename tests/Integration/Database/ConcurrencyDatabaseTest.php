<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Doctrine\ShareWriteUnit;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\EventListener\YopassConflictListener;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function dirname;
use function getenv;
use function is_string;
use function json_decode;
use function method_exists;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;
use const PHP_VERSION_ID;

/**
 * REQ-RUNTIME-003 (1.21.0) on database servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16+), with two connections on the
 * same database, each with its own application manager and the bundle's private manager (the production default).
 *
 * The one-time reveal is the security-critical path: two reveals of a one-read share never both get the ciphertext, a
 * reveal blocked by another one's row lock answers 409 CONFLICT_001 (nothing spent) and finds the share consumed on
 * retry, and a failure after the read rolls the read back. Every session has a 1 s lock timeout, so a hang fails fast.
 * 2.1.1: two revokes of one share write and dispatch once; a revoke or extension racing a delete dispatches nothing.
 * Set YOPASS_TEST_DATABASE_URL to run (CI job "Databases on …").
 */
#[Group('database')]
final class ConcurrencyDatabaseTest extends TestCase
{
    private string $url;

    /** @var array{host: EntityManager, own: YopassEntityManager, shares: DoctrineOrmShareRepository, retriever: ShareRetriever, logger: ShareAccessLogger, extender: ShareExtender, creator: ShareCreator, revoker: ShareRevoker} */
    private array $a;

    /** @var array{host: EntityManager, own: YopassEntityManager, shares: DoctrineOrmShareRepository, retriever: ShareRetriever, logger: ShareAccessLogger, extender: ShareExtender, creator: ShareCreator, revoker: ShareRevoker} */
    private array $b;

    private CommitLedger $ledgerB;

    /** @var array<string, list<string>> lifecycle events dispatched on each side (2.1.1) */
    private array $dispatched = ['a' => [], 'b' => []];

    protected function setUp(): void
    {
        $url = getenv('YOPASS_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set YOPASS_TEST_DATABASE_URL to run the concurrency tests on a database server.');
        }
        $this->url = $url;

        $this->a       = $this->side(null, 'a');
        $this->ledgerB = new CommitLedger();
        $this->b       = $this->side($this->ledgerB, 'b');

        $tool     = new SchemaTool($this->a['host']);
        $metadata = $this->a['host']->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        foreach ([$this->a ?? null, $this->b ?? null] as $side) {
            if ($side === null) {
                continue;
            }
            $connection = $side['host']->getConnection();
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        }
    }

    public function testTwoRevealsOfAOneTimeShareNeverBothGetTheSecret(): void
    {
        $id = $this->share(1)->getId();

        // Worker A is inside its consume unit: the read is taken (row locked) and its access log row written, nothing
        // committed yet.
        $connectionA = $this->a['host']->getConnection();
        $connectionA->beginTransaction();
        $read = $this->a['shares']->consumeReadIfAvailable($id);
        self::assertInstanceOf(SecureShare::class, $read);
        $this->a['logger']->logSuccessfulConsume($read, $this->consumeRequest());

        // Worker B reveals at the same time: it waits for A's row lock, times out, and nothing of B is spent.
        $failure = $this->failure(fn (): array => $this->consume($this->b, $id));
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        $answer = $this->consumeAnswer($failure);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(ConflictCode::RETRY_SAFE, $answer->headers->get('X-Conflict-Code'));
        self::assertSame('1', $answer->headers->get('Retry-After'));
        self::assertSame(['status' => 'conflict', 'code' => ConflictCode::RETRY_SAFE], json_decode((string) $answer->getContent(), true, 512, JSON_THROW_ON_ERROR));

        // A commits: B's retry finds the share consumed; exactly one read and one access log row are stored.
        $connectionA->commit();
        self::assertSame(['status' => 'consumed'], $this->consume($this->b, $id));
        $this->assertStored($id, 0, 1);
        self::assertSame(1, (int) $this->b['host']->getConnection()->fetchOne('SELECT 1'), 'B\'s connection is still usable');
    }

    public function testARevealBlockedByARolledBackReadGetsTheSecretOnRetry(): void
    {
        $id = $this->share(1)->getId();

        $connectionA = $this->a['host']->getConnection();
        $connectionA->beginTransaction();
        self::assertInstanceOf(SecureShare::class, $this->a['shares']->consumeReadIfAvailable($id));
        self::assertInstanceOf(TransientStorageFailure::class, $this->failure(fn (): array => $this->consume($this->b, $id)));
        $connectionA->rollBack();

        $result = $this->consume($this->b, $id);
        self::assertSame('ok', $result['status']);
        self::assertSame('cipher', $result['ciphertext'] ?? null);
        $this->assertStored($id, 0, 1);
    }

    public function testSequentialRevealsOfAOneTimeShareGiveTheSecretOnce(): void
    {
        $id = $this->share(1)->getId();

        self::assertSame('ok', $this->consume($this->a, $id)['status']);
        self::assertSame(['status' => 'consumed'], $this->consume($this->b, $id));
        self::assertSame(['status' => 'consumed'], $this->consume($this->a, $id));
        $this->assertStored($id, 0, 1);
    }

    public function testAFailureAfterTheReadRollsTheReadBack(): void
    {
        $id = $this->share(1)->getId();

        $failure = $this->failure(fn (): array => $this->b['retriever']->consume($id, static function (): never {
            throw new RuntimeException('access log failed');
        }));
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('access log failed', $failure->getMessage());
        $this->assertStored($id, 1, 0);

        self::assertSame('ok', $this->consume($this->b, $id)['status'], 'the read was not spent');
        $this->assertStored($id, 0, 1);
    }

    public function testACommitEarlierInTheRequestAnswersConflict002WithoutRetryAfter(): void
    {
        $id       = $this->share(1)->getId();
        $listener = new YopassConflictListener(null, $this->ledgerB);
        $request  = $this->consumeRequest();
        $listener->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        // Earlier in B's request: a committed write on B's connection (counted by the commit-tracking middleware).
        $this->b['creator']->create(new SecurityUserDto('bob'), $this->data(1));

        $connectionA = $this->a['host']->getConnection();
        $connectionA->beginTransaction();
        $this->a['shares']->consumeReadIfAvailable($id);
        $failure = $this->failure(fn (): array => $this->consume($this->b, $id));
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        $connectionA->rollBack();

        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        $answer = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $answer);
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(ConflictCode::COMMITTED, $answer->headers->get('X-Conflict-Code'));
        self::assertFalse($answer->headers->has('Retry-After'));
        $this->assertStored($id, 1, 0);
    }

    public function testConcurrentExtensionsNeverMoveTheExpirationBack(): void
    {
        $share = $this->share(1);
        $id    = $share->getId();
        // B loaded the share before A's extension (a stale identity map in another worker).
        $stale = $this->b['shares']->find($id);
        self::assertInstanceOf(SecureShare::class, $stale);

        $fresh = $this->a['shares']->find($id);
        self::assertInstanceOf(SecureShare::class, $fresh);
        $this->a['extender']->extend($fresh, '7d', null);
        $extended = $this->expiresAt($id);

        $failure = $this->failure(function () use ($stale): array {
            $this->b['extender']->extend($stale, '1h', 3);

            return [];
        });
        self::assertInstanceOf(ShareExtendException::class, $failure);
        self::assertSame('expiration_not_extended', $failure->errorCode);
        self::assertSame($extended, $this->expiresAt($id), 'the later expiration stays');
        self::assertSame([1, 1], $this->reads($id), 'the max reads raise of the refused extension was rolled back');
    }

    public function testAnExtensionBlockedByARevealAnswersConflict001AndChangesNothing(): void
    {
        $id    = $this->share(1)->getId();
        $share = $this->b['shares']->find($id);
        self::assertInstanceOf(SecureShare::class, $share);

        $connectionA = $this->a['host']->getConnection();
        $connectionA->beginTransaction();
        $this->a['shares']->consumeReadIfAvailable($id);
        $failure = $this->failure(function () use ($share): array {
            $this->b['extender']->extend($share, '7d', 3);

            return [];
        });
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        $connectionA->commit();

        self::assertSame([1, 0], $this->reads($id), 'A\'s read is stored, B\'s raise is not');
        $request = Request::create('/tools/yopass/' . $id . '/extend', 'POST');
        $request->attributes->set('_controller', 'Nowo\\YopassBundle\\Controller\\ShareManageController::extend');
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        (new YopassConflictListener())->onException($event);
        self::assertSame(ConflictCode::RETRY_SAFE, $event->getResponse()?->headers->get('X-Conflict-Code'));

        // The retry (a fresh read) succeeds: max reads 3, two reads left after A's consumed one.
        $retry = $this->b['shares']->find($id);
        self::assertInstanceOf(SecureShare::class, $retry);
        $this->b['extender']->extend($retry, null, 3);
        self::assertSame([3, 2], $this->reads($id));
    }

    /**
     * 2.1.1 (audit YD1-L1): two workers revoke the same share, both loaded it before either wrote. The first holds the
     * row lock until its commit; the second waits, times out (nothing written) and on retry matches nothing: only one
     * revoke writes and dispatches `share_revoked`, and the first revocation date stays.
     */
    public function testTwoConcurrentRevokesWriteAndDispatchOnce(): void
    {
        $id     = $this->share(3)->getId();
        $staleA = $this->a['shares']->find($id);
        $staleB = $this->b['shares']->find($id);
        self::assertInstanceOf(SecureShare::class, $staleA);
        self::assertInstanceOf(SecureShare::class, $staleB);

        $connectionA = $this->a['host']->getConnection();
        $connectionA->beginTransaction();
        self::assertTrue($this->a['revoker']->revoke($staleA));
        $blocked = $this->failure(function () use ($staleB): array {
            $this->b['revoker']->revoke($staleB);

            return [];
        });
        self::assertNotNull($blocked, 'B waited for A\'s row lock and timed out');
        $connectionA->commit();
        $revokedAt = $this->revokedAt($id);
        self::assertNotNull($revokedAt);

        self::assertFalse($this->b['revoker']->revoke($staleB), 'B\'s stale copy matches nothing');

        self::assertSame([YopassEvents::SHARE_REVOKED], $this->dispatched['a']);
        self::assertSame([], $this->dispatched['b']);
        self::assertSame($revokedAt, $this->revokedAt($id), 'The first revocation date stays');
        self::assertSame([3, 0], $this->reads($id));
    }

    /**
     * 2.1.1: the second of two revokes that did not overlap in time, from a copy loaded before the first one, writes
     * and dispatches nothing (before 2.1.1 the in-memory check let both write and dispatch).
     */
    public function testARevokeFromAStaleCopyAfterAnotherRevokeWritesNothing(): void
    {
        $id    = $this->share(3)->getId();
        $stale = $this->b['shares']->find($id);
        self::assertInstanceOf(SecureShare::class, $stale);

        $fresh = $this->a['shares']->find($id);
        self::assertInstanceOf(SecureShare::class, $fresh);
        self::assertTrue($this->a['revoker']->revoke($fresh));
        $revokedAt = $this->revokedAt($id);

        self::assertNull($stale->getRevokedAt(), 'B still sees the share active');
        self::assertFalse($this->b['revoker']->revoke($stale));
        self::assertSame([YopassEvents::SHARE_REVOKED], $this->dispatched['a']);
        self::assertSame([], $this->dispatched['b']);
        self::assertSame($revokedAt, $this->revokedAt($id));
    }

    /**
     * 2.1.1: a revoke or an extension of a share another worker deleted after it was loaded writes nothing, dispatches
     * nothing and never brings the row back; the extension is refused with `not_found`.
     */
    public function testARevokeOrAnExtensionRacingADeleteDispatchesNothing(): void
    {
        $id    = $this->share(1)->getId();
        $stale = $this->b['shares']->find($id);
        self::assertInstanceOf(SecureShare::class, $stale);

        self::assertSame(1, $this->a['shares']->removeByIds([$id]));

        self::assertFalse($this->b['revoker']->revoke($stale));
        foreach ([['7d', 3], ['7d', null], [null, 3]] as [$expiresIn, $maxReads]) {
            $failure = $this->failure(function () use ($stale, $expiresIn, $maxReads): array {
                $this->b['extender']->extend($stale, $expiresIn, $maxReads);

                return [];
            });
            self::assertInstanceOf(ShareExtendException::class, $failure);
            self::assertSame('not_found', $failure->errorCode);
        }

        self::assertSame([], $this->dispatched['b']);
        self::assertSame(0, (int) $this->a['host']->getConnection()->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares WHERE id = ?', [$id]));
    }

    public function testTheRetentionPurgeDeletesOnlyExpiredRowsInChunks(): void
    {
        $old = [];
        for ($i = 0; $i < 3; ++$i) {
            $old[] = $this->share(1)->getId();
        }
        $kept       = $this->share(1)->getId();
        $connection = $this->a['host']->getConnection();
        foreach ($old as $id) {
            $connection->executeStatement('UPDATE yopass_secure_shares SET created_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $id]);
        }

        self::assertSame(3, $this->a['shares']->removeOlderThan(new DateTimeImmutable('2021-01-01')));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares'));
        self::assertSame($kept, $connection->fetchOne('SELECT id FROM yopass_secure_shares'));
    }

    /**
     * @return array{host: EntityManager, own: YopassEntityManager, shares: DoctrineOrmShareRepository, retriever: ShareRetriever, logger: ShareAccessLogger, extender: ShareExtender, creator: ShareCreator, revoker: ShareRevoker}
     */
    private function side(?CommitLedger $ledger, string $name): array
    {
        $paths  = [dirname(__DIR__, 3) . '/src/Entity'];
        $config = PHP_VERSION_ID >= 80400 && method_exists(ORMSetup::class, 'createAttributeMetadataConfig')
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true, sys_get_temp_dir() . '/yopass-orm-concurrency-proxies');
        if (PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new SecureShareMetadataListener('yopass_secure_shares', SecurityUserDto::class, 'yopass_share_access_logs', CreatorMapping::ID));
        $dbal = new DbalConfiguration();
        if ($ledger instanceof CommitLedger) {
            $dbal->setMiddlewares([new CommitTrackingMiddleware($ledger)]);
        }
        $params     = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($this->url);
        $connection = DriverManager::getConnection($params, $dbal);
        $this->lockTimeout($connection);
        $host = new EntityManager($connection, $config, $events);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($host);
        $own      = new YopassEntityManager($registry, 'default');
        $resolver = new DoctrineCreatorReferenceResolver(SecurityUserDto::class, null, null, new CreatorClassPolicy(SecurityUserDto::class), CreatorMapping::ID);
        $shares   = new DoctrineOrmShareRepository($host, null, $registry, 'default', $resolver, $own);
        $unit     = new ShareWriteUnit($own);
        $events   = new EventDispatcher();
        foreach ([YopassEvents::SHARE_REVOKED, YopassEvents::SHARE_EXTENDED] as $event) {
            $events->addListener($event, function () use ($event, $name): void {
                $this->dispatched[$name][] = $event;
            });
        }

        return [
            'host'      => $host,
            'own'       => $own,
            'shares'    => $shares,
            'retriever' => new ShareRetriever($shares, null, null, $unit),
            'logger'    => new ShareAccessLogger(new DoctrineOrmShareAccessLogRepository($host, $registry, 'default', $own), true),
            'extender'  => new ShareExtender($shares, DefaultShareOptions::get(), null, $events, null, $unit),
            'creator'   => new ShareCreator($shares, DefaultShareOptions::get()['expiration_options'], null, null, $resolver),
            'revoker'   => new ShareRevoker($shares, $events),
        ];
    }

    /**
     * A 1 s lock timeout in the test session: a lock wait fails fast instead of holding the machine.
     */
    private function lockTimeout(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement("SET lock_timeout = '1s'");
        }
    }

    /**
     * The public controller's consume: the read and its access log row in one unit.
     *
     * @param array{retriever: ShareRetriever, logger: ShareAccessLogger} $side
     *
     * @return array{status: string, ciphertext?: string, mode?: string}
     */
    private function consume(array $side, string $id): array
    {
        $request = $this->consumeRequest();

        return $side['retriever']->consume($id, static function (SecureShare $share) use ($side, $request): void {
            $side['logger']->logSuccessfulConsume($share, $request);
        });
    }

    private function consumeRequest(): Request
    {
        $request = Request::create('/share/x/consume', 'POST');
        $request->attributes->set('_controller', 'Nowo\\YopassBundle\\Controller\\PublicShareController::consume');

        return $request;
    }

    private function consumeAnswer(Throwable $failure): JsonResponse
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $this->consumeRequest(), HttpKernelInterface::MAIN_REQUEST, $failure);
        (new YopassConflictListener())->onException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    /**
     * @param callable(): array<mixed> $operation
     */
    private function failure(callable $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function share(int $maxReads): SecureShare
    {
        $share = $this->a['creator']->create(new SecurityUserDto('alice'), $this->data($maxReads));
        $this->a['own']->reset();

        return $share;
    }

    private function data(int $maxReads): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = $maxReads;

        return $data;
    }

    private function assertStored(string $id, int $readsLeft, int $logRows): void
    {
        $connection = $this->a['host']->getConnection();
        self::assertSame($readsLeft, (int) $connection->fetchOne('SELECT reads_left FROM yopass_secure_shares WHERE id = ?', [$id]));
        self::assertSame($logRows, (int) $connection->fetchOne('SELECT COUNT(*) FROM yopass_share_access_logs WHERE share_id = ?', [$id]));
    }

    /**
     * @return array{int, int} max reads and reads left as stored
     */
    private function reads(string $id): array
    {
        /** @var array{max_reads: int|string, reads_left: int|string} $row */
        $row = $this->a['host']->getConnection()->fetchAssociative('SELECT max_reads, reads_left FROM yopass_secure_shares WHERE id = ?', [$id]);

        return [(int) $row['max_reads'], (int) $row['reads_left']];
    }

    private function revokedAt(string $id): ?string
    {
        $value = $this->a['host']->getConnection()->fetchOne('SELECT revoked_at FROM yopass_secure_shares WHERE id = ?', [$id]);

        return $value === false || $value === null ? null : (string) $value;
    }

    private function expiresAt(string $id): string
    {
        return (string) $this->a['host']->getConnection()->fetchOne('SELECT expires_at FROM yopass_secure_shares WHERE id = ?', [$id]);
    }
}
