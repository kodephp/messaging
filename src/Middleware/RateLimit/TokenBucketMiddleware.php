<?php

declare(strict_types=1);

namespace Kode\Messaging\Middleware\RateLimit;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Contract\MiddlewareInterface;
use Kode\Messaging\Exception\MessagingException;

/**
 * 令牌桶限流中间件（基于 kode/limiting）
 *
 * - 默认算法：`Kode\Limiting\Limiter::tokenBucket()`（令牌桶）
 * - 默认存储：内存（单进程），可注入 Redis 存储做分布式限流
 * - 触发限流时抛 `MessagingException(429)`，附等待时间与剩余令牌数
 *
 * 键的优先级：
 *  1. `connection_id`（最稳定，跨 IP 重启后不变）
 *  2. `remote_address`（连接 IP+端口）
 *  3. `user_id`（业务身份标识）
 *  4. `*`（兜底全局桶）
 *
 * 用例：
 *   $mw = new TokenBucketMiddleware(capacity: 100, refillRate: 10.0);
 *   // 或注入分布式限流器
 *   $mw = TokenBucketMiddleware::distributed('127.0.0.1', 6379, 100, 10.0);
 *
 *   Messaging::server('rtmp://...')
 *       ->middleware($mw)
 *       ->start();
 */
final class TokenBucketMiddleware implements MiddlewareInterface
{
    /**
     * @param null|RateLimiterInterface $limiter 自定义限流器（不传则使用内部令牌桶）
     * @param int                       $capacity 令牌桶容量（仅在内部创建限流器时使用）
     * @param float                     $refillRate 每秒补充令牌数（仅在内部创建限流器时使用）
     * @param string                    $keyPrefix 键前缀（多租户场景区分）
     * @param string                    $keyField  上下文中的键字段名（默认 connection_id）
     */
    public function __construct(
        private readonly ?RateLimiterInterface $limiter = null,
        private readonly int $capacity = 100,
        private readonly float $refillRate = 60.0,
        private readonly string $keyPrefix = 'rtmp:',
        private readonly string $keyField = 'connection_id',
    ) {}

    /**
     * 创建内存版令牌桶中间件（默认）。
     */
    public static function memory(
        int $capacity = 100,
        float $refillRate = 60.0,
        string $keyPrefix = 'rtmp:',
        string $keyField = 'connection_id',
    ): self {
        return new self(null, $capacity, $refillRate, $keyPrefix, $keyField);
    }

    /**
     * 创建分布式（Redis）版令牌桶中间件。
     *
     * 跨机器限流，依赖 `kode/limiting` 的 `RedisStore` + Lua 原子操作。
     */
    public static function distributed(
        string $redisHost,
        int $redisPort,
        int $capacity = 1000,
        float $refillRate = 100.0,
        ?string $password = null,
        int $database = 0,
        string $keyPrefix = 'rtmp:',
        string $keyField = 'connection_id',
    ): self {
        $limiter = \Kode\Limiting\Limiter::redis(
            \Kode\Limiting\Enum\LimiterType::TOKEN_BUCKET,
            $capacity,
            $refillRate,
            $redisHost,
            $redisPort,
            $password,
            $database,
        );

        return new self($limiter->build(), $capacity, $refillRate, $keyPrefix, $keyField);
    }

    /**
     * 创建注入自定义限流器的中间件。
     */
    public static function wrap(RateLimiterInterface $limiter, string $keyPrefix = 'rtmp:'): self
    {
        return new self($limiter, 0, 0.0, $keyPrefix);
    }

    public function process(MessageInterface $message, callable $next): MessageInterface
    {
        $key = $this->bucketKey($message);
        $limiter = $this->limiter ?? $this->defaultLimiter();
        if (! $limiter->allow($key, 1)) {
            throw new MessagingException('限流触发（令牌桶）', 429, [
                'algorithm' => 'token_bucket',
                'bucket' => $key,
                'capacity' => $this->capacity,
                'refill_rate' => $this->refillRate,
                'wait_time' => $limiter->getWaitTime($key),
                'remaining' => $limiter->getRemaining($key),
            ]);
        }

        return $next($message);
    }

    /**
     * 懒加载默认限流器（按 capacity + refillRate 缓存，单进程内同配置复用）。
     */
    private function defaultLimiter(): RateLimiterInterface
    {
        $key = $this->capacity.':'.$this->refillRate;
        static $cache = [];
        if (! isset($cache[$key])) {
            $cache[$key] = \Kode\Limiting\Limiter::tokenBucket(
                $this->capacity,
                $this->refillRate,
            )->build();
        }

        return $cache[$key];
    }

    private function bucketKey(MessageInterface $m): string
    {
        $ctx = $m->context();
        if (isset($ctx[$this->keyField]) && is_string($ctx[$this->keyField]) && $ctx[$this->keyField] !== '') {
            return $this->keyPrefix.$ctx[$this->keyField];
        }
        if (isset($ctx['remote_address']) && is_string($ctx['remote_address'])) {
            return $this->keyPrefix.$ctx['remote_address'];
        }

        return $this->keyPrefix.'*';
    }
}
