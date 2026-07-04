<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShareAccessLoggerTest extends TestCase
{
    public function testLogSuccessfulConsumePersistsWhenEnabled(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000001', new TestUser());
        $share
            ->setCiphertext('x')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(3);
        $share->consumeRead();
        $share->consumeRead();

        $repository = $this->createMock(ShareAccessLogRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (ShareAccessLog $log) use ($share): bool {
                return $log->getShare() === $share
                    && $log->getReadNumber() === 2
                    && $log->getIpAddress() === '203.0.113.10'
                    && str_contains((string) $log->getUserAgent(), 'TestAgent');
            }));
        $repository->expects(self::once())->method('flush');

        $logger = new ShareAccessLogger($repository, true);
        $request = Request::create('/', 'POST', server: [
            'REMOTE_ADDR'     => '203.0.113.10',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);

        $logger->logSuccessfulConsume($share, $request);
    }

    public function testLogSuccessfulConsumeIsNoOpWhenDisabled(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000002', new TestUser());
        $share
            ->setCiphertext('x')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        $repository = $this->createMock(ShareAccessLogRepositoryInterface::class);
        $repository->expects(self::never())->method('persist');

        (new ShareAccessLogger($repository, false))->logSuccessfulConsume($share, Request::create('/'));
    }

    public function testListForShareReturnsEntriesWhenEnabled(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000003', new TestUser());
        $log     = new ShareAccessLog('00000000-0000-4000-8000-000000000099', $share, 1, '127.0.0.1', 'Agent');

        $repository = $this->createMock(ShareAccessLogRepositoryInterface::class);
        $repository->method('findByShare')->willReturn([$log]);

        $entries = (new ShareAccessLogger($repository, true))->listForShare($share);

        self::assertCount(1, $entries);
        self::assertSame(1, $entries[0]['readNumber']);
        self::assertSame('127.0.0.1', $entries[0]['ipAddress']);
    }

    public function testListForShareReturnsEmptyWhenDisabled(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000004', new TestUser());

        $repository = $this->createMock(ShareAccessLogRepositoryInterface::class);
        $repository->expects(self::never())->method('findByShare');

        self::assertSame([], (new ShareAccessLogger($repository, false))->listForShare($share));
    }
}
