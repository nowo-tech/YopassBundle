<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Integration\DddHost\DddHostTestKernel;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

use function array_map;
use function array_unique;
use function array_values;
use function extension_loaded;
use function gc_collect_cycles;
use function intval;
use function memory_get_usage;
use function sprintf;

/**
 * REQ-RUNTIME-002 (1.20.0): one kept-alive kernel (FrankenPHP worker, kernel reset false), the `services_resetter`
 * never called, main requests through `http_kernel` (never `Kernel::handle()`, which would reset the services) and
 * Messenger messages through the worker events, alternating two users. Every unit creates a share as its user and
 * lists that user's shares through the bundle's private entity manager.
 *
 * Asserted: each user only ever sees their own shares (isolation), the private manager never survives a unit (dropped
 * at the outermost boundaries, so no share of the previous user stays managed), the application's manager is never
 * flushed by the bundle, and memory stays flat after the warm-up.
 */
final class WorkerKeptAliveKernelTest extends TestCase
{
    private const ITERATIONS = 1000;

    /** Allowed growth after the warm-up (the shares table grows by one row per unit; nothing may stay managed). */
    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private const WARM_UP = 100;

    private ?DddHostTestKernel $kernel = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(DddHostTestKernel::baseDir());
    }

    public function testTwoUsersAlternateInOneKeptAliveKernelWithoutTheServicesResetter(): void
    {
        $this->kernel = new DddHostTestKernel(false, ['database' => ['platform' => 'sqlite', 'entity_manager' => 'default']]);
        $this->kernel->boot();
        $container = $this->service($this->kernel->getContainer(), 'test.service_container', ContainerInterface::class);

        $host = $this->service($container, 'doctrine.orm.entity_manager', EntityManagerInterface::class);
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        $host->persist(new GuardUser(7, 'alice'));
        $host->persist(new GuardUser(8, 'bob'));
        $host->flush();
        $host->clear();

        $httpKernel = $this->service($container, 'http_kernel', HttpKernel::class);
        $dispatcher = $this->service($container, 'event_dispatcher', EventDispatcherInterface::class);
        $own        = $this->service($container, YopassEntityManager::class, YopassEntityManager::class);
        $creator    = $this->service($container, ShareCreator::class, ShareCreator::class);
        $retriever  = $this->service($container, ShareRetriever::class, ShareRetriever::class);
        $shares     = $this->service($container, ShareRepositoryInterface::class, ShareRepositoryInterface::class);

        // The application's own pending change: never flushed by a bundle write, never cleared by a request unit.
        $hostPending = new GuardUser(9, 'carol');
        $host->persist($hostPending);
        $created          = [7 => 0, 8 => 0];
        $baseline         = 0;
        $leftOverManagers = 0;
        $foreignShareSeen = 0;
        $hostTouched      = 0;

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $userId = $i % 2 === 0 ? 7 : 8;
            $unit   = function () use ($userId, $host, $own, $creator, $retriever, $shares, &$created, &$leftOverManagers, &$foreignShareSeen): void {
                if ($own->isCreated()) {
                    ++$leftOverManagers;
                }
                $user  = $host->getReference(GuardUser::class, $userId);
                $share = $creator->create($user, $this->data());
                ++$created[$userId];
                self::assertSame('ok', $retriever->consume($share->getId())['status']);

                $mine = $shares->findByCreator($user);
                self::assertCount($created[$userId], $mine);
                foreach ($mine as $row) {
                    self::assertInstanceOf(SecureShare::class, $row);
                    if ($row->getCreatorId() !== (string) $userId) {
                        ++$foreignShareSeen;
                    }
                }
            };

            if ($i % 5 === 4) {
                // A Messenger message handled by the same kernel.
                $envelope = new Envelope(new stdClass());
                $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
                $unit();
                $dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'async'));
                // DoctrineBundle's own Messenger subscriber clears every manager after a message (host behaviour, not
                // the bundle's): the application stages its pending change again.
                $host->persist($hostPending);
            } else {
                $request = Request::create('/worker-unit');
                $request->attributes->set('_controller', static function () use ($unit): Response {
                    $unit();

                    return new Response('ok');
                });
                $response = $httpKernel->handle($request);
                self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
                $httpKernel->terminate($request, $response);
                if (!$host->getUnitOfWork()->isScheduledForInsert($hostPending)) {
                    ++$hostTouched;
                    $host->persist($hostPending);
                }
            }

            self::assertFalse($own->isCreated(), sprintf('Unit %d: the private manager must be dropped at the boundary.', $i));

            if ($i + 1 === self::WARM_UP) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        $growth = memory_get_usage() - $baseline;

        self::assertSame(0, $leftOverManagers, 'No unit starts with the previous unit\'s private manager.');
        self::assertSame(0, $foreignShareSeen, 'A user never sees the other user\'s shares.');
        self::assertSame([7 => self::ITERATIONS / 2, 8 => self::ITERATIONS / 2], $created);
        $db = $host->getConnection();
        self::assertSame(self::ITERATIONS, (int) $db->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares'));
        self::assertSame([7, 8], array_values(array_unique(array_map(intval(...), $db->fetchFirstColumn('SELECT creator_id FROM yopass_secure_shares ORDER BY creator_id')))));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM yopass_secure_shares WHERE reads_left <> 0'), 'Every share was consumed once.');
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM guard_users WHERE id = 9'), 'Its pending insert was never flushed by the bundle.');
        self::assertSame(0, $hostTouched, 'No request unit flushed or cleared the application manager.');
        self::assertLessThan(self::MEMORY_MARGIN, $growth, sprintf('Memory grew by %d bytes over %d units after the warm-up.', $growth, self::ITERATIONS - self::WARM_UP));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private function service(ContainerInterface $container, string $id, string $type): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($type, $service);

        return $service;
    }

    private function data(): ShareCreateData
    {
        $data             = new ShareCreateData();
        $data->ciphertext = 'cipher';
        $data->maxReads   = 1;

        return $data;
    }
}
