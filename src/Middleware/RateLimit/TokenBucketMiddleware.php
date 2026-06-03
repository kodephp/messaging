<?php

declare(strict_types=1);

namespace Kode\Messaging\Middleware\RateLimit;

use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Contract\MiddlewareInterface;
use Kode\Messaging\Exception\MessagingException;

/**
 * 令牌桶限流（按 connectionId 维度）
 *
 * 每个连接维护一个令牌桶：capacity 个令牌，每秒补充 ratePerSecond 个。
 * 令牌耗尽时抛 MessagingException(429)。
 *
 * 简单内存版；分布式场景请使用 Redis 版的 RateLimiterMiddleware。
 */
final class TokenBucketMiddleware implements MiddlewareInterface
{
    /** @var array<string, array{tokens: float, lastRefill: float}> */
    private array $buckets = [];

    /**
     * @param int   $capacity        桶容量
     * @param float $ratePerSecond   每秒补充令牌数
     */
    public function __construct(
        private readonly int $capacity = 100,
        private readonly float $ratePerSecond = 60.0,
    ) {
    }

    public function process(MessageInterface $message, callable $next): MessageInterface
    {
        $key = $this->bucketKey($message);
        if (!$this->tryConsume($key)) {
            throw new MessagingException('限流触发', 429, [
                'bucket' => $key,
                'capacity' => $this->capacity,
                'rate'     => $this->ratePerSecond,
            ]);
        }
        return $next($message);
    }

    private function bucketKey(MessageInterface $m): string
    {
        // 优先使用连接 ID；其次使用 remoteAddress；最次 fallback 到 '*'
        $context = $m->context();
        if (isset($context['connection_id']) && is_string($context['connection_id'])) {
            return $context['connection_id'];
        }
        if (isset($context['remote_address']) && is_string($context['remote_address'])) {
            return $context['remote_address'];
        }
        return '*';
    }

    private function tryConsume(string $key): bool
    {
        $now = microtime(true);
        $bucket = $this->buckets[$key] ?? ['tokens' => (float)$this->capacity, 'lastRefill' => $now];

        // 补充令牌
        $elapsed = $now - $bucket['lastRefill'];
        $refill = $elapsed * $this->ratePerSecond;
        $bucket['tokens'] = min((float)$this->capacity, $bucket['tokens'] + $refill);
        $bucket['lastRefill'] = $now;

        if ($bucket['tokens'] >= 1.0) {
            $bucket['tokens'] -= 1.0;
            $this->buckets[$key] = $bucket;
            return true;
        }

        $this->buckets[$key] = $bucket;
        return false;
    }
}
