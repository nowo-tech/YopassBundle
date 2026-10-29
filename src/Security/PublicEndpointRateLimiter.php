<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

use function hash;
use function sprintf;

/**
 * Fixed-window rate limiter for public share endpoints (consume/show).
 */
final readonly class PublicEndpointRateLimiter
{
    private const CACHE_KEY_PREFIX = 'nowo_yopass_public_';

    private LoggerInterface $logger;

    private ClockInterface $clock;

    /**
     * Creates the limiter; a null pool or a zero limit/interval disables limiting.
     *
     * @param CacheItemPoolInterface|null $cachePool Counter storage; null disables limiting
     * @param int $limit Maximum requests per window (0 disables limiting)
     * @param int $intervalSeconds Window length in seconds (0 disables limiting)
     * @param LoggerInterface|null $logger Logger for limit-exceeded warnings
     * @param ClockInterface|null $clock Clock for the fixed window (REQ-DI-001)
     */
    public function __construct(
        private ?CacheItemPoolInterface $cachePool,
        private int $limit,
        private int $intervalSeconds,
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock  = $clock ?? new Clock();
    }

    /**
     * Counts one request for the client IP and action; throws when the window limit is exceeded.
     *
     * @param Request $request Current request (client IP is the counter key)
     * @param string $action Endpoint action name (e.g. "show", "consume")
     *
     * @throws TooManyRequestsHttpException When the limit for the current window is exceeded
     */
    public function consume(Request $request, string $action): void
    {
        if ($this->limit <= 0 || $this->intervalSeconds <= 0 || !$this->cachePool instanceof CacheItemPoolInterface) {
            return;
        }

        $clientIp = $request->getClientIp() ?? 'unknown';
        $key      = self::CACHE_KEY_PREFIX . hash('sha256', $action . '|' . $clientIp);
        $item     = $this->cachePool->getItem($key);
        $now      = $this->clock->now()->getTimestamp();
        $data     = $item->isHit() ? $item->get() : null;

        if ($data === null || !isset($data['s'], $data['c']) || ($now - (int) $data['s']) >= $this->intervalSeconds) {
            $data = ['s' => $now, 'c' => 1];
        } else {
            $data['c'] = (int) $data['c'] + 1;
        }

        if ($data['c'] > $this->limit) {
            // REQ-OBS-001: structured context only — never log ciphertext, keys, or payloads.
            $this->logger->warning('Yopass public endpoint rate limit exceeded', [
                'action'           => $action,
                'limit'            => $this->limit,
                'interval_seconds' => $this->intervalSeconds,
                'client_ip_hash'   => hash('sha256', $clientIp),
            ]);

            throw new TooManyRequestsHttpException($this->intervalSeconds, sprintf('Too many requests. Limit is %d per %d seconds.', $this->limit, $this->intervalSeconds));
        }

        $item->set($data);
        $item->expiresAfter($this->intervalSeconds + 10);
        $this->cachePool->save($item);
    }
}
