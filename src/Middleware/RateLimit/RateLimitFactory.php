<?php

declare(strict_types=1);

namespace Kode\Messaging\Middleware\RateLimit;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Limiter;
use Kode\Limiting\Store\MemcachedStore;
use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\PdoStore;
use Kode\Limiting\Store\RedisStore;
use Kode\Messaging\Exception\MessagingException;

/**
 * 限流器工厂（基于 kode/limiting）
 *
 * 把数组配置转换为已就绪的限流器实例，避免业务代码硬编码。
 *
 * 配置形态（参考 config/messaging.php 中的 'rate_limit' 段）：
 * ```php
 * [
 *     'driver'     => 'token_bucket',   // token_bucket | sliding_window
 *     'capacity'   => 100,              // 容量（令牌数 / 窗口内最大请求数）
 *     'rate'       => 10.0,             // 令牌桶每秒补充速率
 *     'window'     => 1.0,              // 滑动窗口大小（秒），仅 sliding_window 使用
 *     'store'      => 'memory',         // memory | redis | memcached | pdo
 *     'store_opts' => [                 // 存储相关参数
 *         'host'     => '127.0.0.1',
 *         'port'     => 6379,
 *         'password' => null,
 *         'database' => 0,
 *         'prefix'   => 'messaging:',
 *         'dsn'      => 'sqlite::memory:',  // pdo 模式
 *     ],
 *     'ttl'        => 3600,             // key 过期时间（秒）
 *     'prefix'     => 'rl:',            // 限流 key 前缀
 * ]
 * ```
 *
 * 用例：
 *   $config = $messagingConfig['rate_limit']['rtmp'] ?? [];
 *   $limiter = RateLimitFactory::create($config);
 *
 *   Messaging::server('rtmp://...')
 *       ->middleware(TokenBucketMiddleware::wrap($limiter))
 *       ->start();
 */
final class RateLimitFactory
{
    /**
     * 根据配置数组构造限流器。
     *
     * @param array<string, mixed> $config 限流配置
     */
    public static function create(array $config): RateLimiterInterface
    {
        if ($config === []) {
            throw new MessagingException('限流配置不能为空', 500);
        }

        $driver   = (string)($config['driver'] ?? 'token_bucket');
        $capacity = (int)($config['capacity'] ?? 100);
        $rate     = (float)($config['rate'] ?? 1.0);
        $window   = (float)($config['window'] ?? 1.0);
        $storeKey = strtolower((string)($config['store'] ?? 'memory'));
        $opts     = \is_array($config['store_opts'] ?? null) ? $config['store_opts'] : [];

        $limiterType = self::resolveLimiterType($driver);

        // Redis 走专用工厂（自带 prefix / store），与 memory / memcached / pdo 分支不同
        if ($storeKey === 'redis') {
            $limiter = Limiter::redis(
                $limiterType,
                $capacity,
                $limiterType === LimiterType::SLIDING_WINDOW ? $window : $rate,
                (string)($opts['host']     ?? '127.0.0.1'),
                (int)($opts['port']        ?? 6379),
                $opts['password'] ?? null,
                (int)($opts['database']    ?? 0),
            );
            return $limiter->build();
        }

        // 非 Redis：构造对应 store 后用 tokenBucket / slidingWindow 工厂
        $store = self::resolveStore($storeKey, $opts);
        $limiter = $limiterType === LimiterType::SLIDING_WINDOW
            ? Limiter::slidingWindow($capacity, $window, $store)
            : Limiter::tokenBucket($capacity, $rate, $store);

        return $limiter->build();
    }

    /**
     * 直接构造中间件（限流配置 + 业务键前缀）。
     *
     * @param array<string, mixed> $config 限流配置
     */
    public static function middleware(array $config, string $keyPrefix = ''): \Kode\Messaging\Contract\MiddlewareInterface
    {
        $limiter = self::create($config);
        $driver  = (string)($config['driver'] ?? 'token_bucket');

        return match (strtolower($driver)) {
            'sliding_window' => SlidingWindowMiddleware::wrap($limiter, $keyPrefix),
            default          => TokenBucketMiddleware::wrap($limiter, $keyPrefix),
        };
    }

    private static function resolveLimiterType(string $driver): LimiterType
    {
        return match (strtolower($driver)) {
            'token_bucket'   => LimiterType::TOKEN_BUCKET,
            'sliding_window' => LimiterType::SLIDING_WINDOW,
            default => throw new MessagingException(
                sprintf('不支持的限流算法: %s（仅支持 token_bucket / sliding_window）', $driver),
                500,
            ),
        };
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function resolveStore(string $storeKey, array $opts): \Kode\Limiting\Store\StoreInterface
    {
        return match ($storeKey) {
            'memory'    => new MemoryStore(),
            'memcached' => MemcachedStore::create(
                (string)($opts['host'] ?? '127.0.0.1'),
                (int)($opts['port']    ?? 11211),
            ),
            'pdo'       => new PdoStore(
                new \PDO(
                    (string)($opts['dsn'] ?? 'sqlite::memory:'),
                    $opts['username'] ?? null,
                    $opts['password'] ?? null,
                ),
            ),
            'redis'     => throw new MessagingException('resolveStore 不应处理 redis，请走 Limiter::redis()', 500),
            default => throw new MessagingException(
                \sprintf('不支持的限流存储: %s（仅支持 memory / redis / memcached / pdo）', $storeKey),
                500,
            ),
        };
    }
}
