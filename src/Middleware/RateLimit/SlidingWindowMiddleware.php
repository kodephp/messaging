<?php

declare(strict_types=1);

namespace Kode\Messaging\Middleware\RateLimit;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Contract\MiddlewareInterface;
use Kode\Messaging\Exception\MessagingException;

/**
 * 滑动窗口限流中间件（基于 kode/limiting）
 *
 * - 算法：`Kode\Limiting\Limiter::slidingWindow()`
 * - 默认存储：内存（单进程），可注入 Redis 做分布式精确限流
 * - 触发限流时抛 `MessagingException(429)`
 *
 * 与 TokenBucketMiddleware 的区别：
 *  - 令牌桶：允许突发（capacity 容量可瞬间消耗），适合"短时尖刺"流量整形
 *  - 滑动窗口：严格按时间窗口计数，适合"瞬时流量精确控制"（如 API QPS）
 *
 * 用例（API 限流）：
 *   $mw = new SlidingWindowMiddleware(capacity: 1000, windowSize: 1.0);
 *   // 1 秒内最多 1000 个请求
 *
 *   Messaging::server('rtmp://...')
 *       ->middleware($mw)
 *       ->start();
 */
final class SlidingWindowMiddleware implements MiddlewareInterface
{
    /**
     * @param null|RateLimiterInterface $limiter    自定义限流器
     * @param int                       $capacity   窗口内允许的请求数
     * @param float                     $windowSize 窗口大小（秒）
     * @param string                    $keyPrefix  键前缀
     * @param string                    $keyField   上下文中的键字段名
     */
    public function __construct(
        private readonly ?RateLimiterInterface $limiter = null,
        private readonly int $capacity = 100,
        private readonly float $windowSize = 1.0,
        private readonly string $keyPrefix = 'sw:',
        private readonly string $keyField = 'connection_id',
    ) {}

    /**
     * 创建内存版滑动窗口中间件（默认）。
     */
    public static function memory(
        int $capacity = 100,
        float $windowSize = 1.0,
        string $keyPrefix = 'sw:',
        string $keyField = 'connection_id',
    ): self {
        return new self(null, $capacity, $windowSize, $keyPrefix, $keyField);
    }

    /**
     * 创建分布式（Redis）版滑动窗口中间件。
     *
     * 跨机器精确限流，依赖 `kode/limiting` 的 `RedisStore` + Lua 原子操作。
     */
    public static function distributed(
        string $redisHost,
        int $redisPort,
        int $capacity = 1000,
        float $windowSize = 1.0,
        ?string $password = null,
        int $database = 0,
        string $keyPrefix = 'sw:',
        string $keyField = 'connection_id',
    ): self {
        $limiter = \Kode\Limiting\Limiter::redis(
            \Kode\Limiting\Enum\LimiterType::SLIDING_WINDOW,
            $capacity,
            $windowSize,
            $redisHost,
            $redisPort,
            $password,
            $database,
        );

        return new self($limiter->build(), $capacity, $windowSize, $keyPrefix, $keyField);
    }

    /**
     * 创建注入自定义限流器的中间件。
     */
    public static function wrap(RateLimiterInterface $limiter, string $keyPrefix = 'sw:'): self
    {
        return new self($limiter, 0, 0.0, $keyPrefix);
    }

    public function process(MessageInterface $message, callable $next): MessageInterface
    {
        $key = $this->bucketKey($message);
        $limiter = $this->limiter ?? $this->defaultLimiter();
        if (! $limiter->allow($key, 1)) {
            throw new MessagingException('限流触发（滑动窗口）', 429, [
                'algorithm' => 'sliding_window',
                'bucket' => $key,
                'capacity' => $this->capacity,
                'window_size' => $this->windowSize,
                'wait_time' => $limiter->getWaitTime($key),
                'remaining' => $limiter->getRemaining($key),
            ]);
        }

        return $next($message);
    }

    private function defaultLimiter(): RateLimiterInterface
    {
        $key = $this->capacity.':'.$this->windowSize;
        static $cache = [];
        if (! isset($cache[$key])) {
            $cache[$key] = \Kode\Limiting\Limiter::slidingWindow(
                $this->capacity,
                $this->windowSize,
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
