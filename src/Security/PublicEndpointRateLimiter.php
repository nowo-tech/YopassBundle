<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

use function hash;
use function sprintf;
use function time;

/**
 * Fixed-window rate limiter for public share endpoints (consume/show).
 */
final readonly class PublicEndpointRateLimiter
{
    private const CACHE_KEY_PREFIX = 'nowo_yopass_public_';

    public function __construct(
        private ?CacheItemPoolInterface $cachePool,
        private int $limit,
        private int $intervalSeconds,
    ) {
    }

    public function consume(Request $request, string $action): void
    {
        if ($this->limit <= 0 || $this->intervalSeconds <= 0 || !$this->cachePool instanceof CacheItemPoolInterface) {
            return;
        }

        $key  = self::CACHE_KEY_PREFIX . hash('sha256', $action . '|' . ($request->getClientIp() ?? 'unknown'));
        $item = $this->cachePool->getItem($key);
        $now  = time();
        $data = $item->isHit() ? $item->get() : null;

        if ($data === null || !isset($data['s'], $data['c']) || ($now - (int) $data['s']) >= $this->intervalSeconds) {
            $data = ['s' => $now, 'c' => 1];
        } else {
            $data['c'] = (int) $data['c'] + 1;
        }

        if ($data['c'] > $this->limit) {
            throw new TooManyRequestsHttpException($this->intervalSeconds, sprintf('Too many requests. Limit is %d per %d seconds.', $this->limit, $this->intervalSeconds));
        }

        $item->set($data);
        $item->expiresAfter($this->intervalSeconds + 10);
        $this->cachePool->save($item);
    }
}
