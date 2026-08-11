<?php

declare(strict_types=1);

namespace Kode\Messaging\Middleware\RateLimit;

use function in_array;
use function is_array;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Enum\RedisMode;
use Kode\Limiting\Limiter;
use Kode\Limiting\Store\ApcuStore;
use Kode\Limiting\Store\MemcachedStore;
use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\PdoStore;
use Kode\Messaging\Exception\MessagingException;
use PDO;

/**
 * 限流器工厂（基于 kode/limiting 2.x）
 *
 * 把数组配置转换为已就绪的限流器实例，避免业务代码硬编码。
 *
 * 配置形态（参考 config/messaging.php 中的 'rate_limit' 段）：
 * ```php
 * [
 *     'driver'     => 'token_bucket',   // token_bucket | sliding_window | sliding_window_counter | leaky_bucket | counter
 *     'capacity'   => 100,              // 容量（令牌数 / 窗口内最大请求数 / 计数器上限）
 *     'rate'       => 10.0,             // 令牌桶 / 漏桶每秒补充速率
 *     'window'     => 1.0,              // 滑动窗口 / 滑动窗口计数器 / 计数器窗口大小（秒）
 *     'store'      => 'memory',         // memory | redis | memcached | pdo | apcu
 *     'store_opts' => [                 // 存储相关参数
 *         'host'     => '127.0.0.1',
 *         'port'     => 6379,
 *         'password' => null,
 *         'database' => 0,
 *         'prefix'   => 'messaging:',
 *         'dsn'      => 'sqlite::memory:',  // pdo 模式
 *         // Redis 高可用（kode/limiting 2.x 新增）
 *         'mode'         => 'standalone',  // standalone | sentinel | cluster
 *         'sentinels'    => ['127.0.0.1:26379'],
 *         'master_name'  => 'mymaster',
 *         'cluster_nodes' => ['127.0.0.1:7000'],
 *     ],
 *     'ttl'        => 3600,             // key 过期时间（秒）
 *     'prefix'     => 'rl:',            // 限流 key 前缀
 * ]
 * ```
 *
 * 算法说明：
 *  - token_bucket          令牌桶：允许突发，按固定速率补充（kode/limiting 2.x）
 *  - sliding_window        滑动窗口（精确日志）
 *  - sliding_window_counter 滑动窗口计数器（加权近似，内存恒定）
 *  - leaky_bucket          漏桶：固定速率漏出，抑制突发
 *  - counter               固定窗口计数器
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
    /** 以「窗口大小（秒）」作为第三参数的算法类型 */
    private const WINDOW_TYPES = [
        LimiterType::SLIDING_WINDOW,
        LimiterType::SLIDING_WINDOW_COUNTER,
        LimiterType::COUNTER,
    ];

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

        $driver = (string) ($config['driver'] ?? 'token_bucket');
        $capacity = (int) ($config['capacity'] ?? 100);
        $rate = (float) ($config['rate'] ?? 1.0);
        $window = (float) ($config['window'] ?? 1.0);
        $storeKey = strtolower((string) ($config['store'] ?? 'memory'));
        $opts = is_array($config['store_opts'] ?? null) ? $config['store_opts'] : [];

        $limiterType = self::resolveLimiterType($driver);

        // Redis 走专用工厂（自带 prefix / store），并支持 sentinel / cluster 模式
        if ($storeKey === 'redis') {
            $limiter = Limiter::redis(
                $limiterType,
                $capacity,
                self::isWindowType($limiterType) ? $window : $rate,
                (string) ($opts['host'] ?? '127.0.0.1'),
                (int) ($opts['port'] ?? 6379),
                $opts['password'] ?? null,
                (int) ($opts['database'] ?? 0),
                self::resolveRedisMode((string) ($opts['mode'] ?? 'standalone')),
                is_array($opts['sentinels'] ?? null) ? $opts['sentinels'] : ['127.0.0.1:26379'],
                (string) ($opts['master_name'] ?? 'mymaster'),
                is_array($opts['cluster_nodes'] ?? null) ? $opts['cluster_nodes'] : ['127.0.0.1:7000'],
            );

            return $limiter->build();
        }

        // 非 Redis：构造对应 store 后用具体算法工厂
        $store = self::resolveStore($storeKey, $opts);

        return (match ($limiterType) {
            LimiterType::TOKEN_BUCKET => Limiter::tokenBucket($capacity, $rate, $store),
            LimiterType::LEAKY_BUCKET => Limiter::leakyBucket($capacity, $rate, $store),
            LimiterType::SLIDING_WINDOW => Limiter::slidingWindow($capacity, $window, $store),
            LimiterType::SLIDING_WINDOW_COUNTER => Limiter::slidingWindowCounter($capacity, (int) $window, $store),
            LimiterType::COUNTER => Limiter::counter($capacity, (int) $window, $store),
        })->build();
    }

    /**
     * 直接构造中间件（限流配置 + 业务键前缀）。
     *
     * 中间件本身算法无关：仅调用 allow() / getWaitTime() / getRemaining()，
     * 因此任意算法（含 2.x 新增的 leaky_bucket / counter / sliding_window_counter）均可复用。
     *
     * @param array<string, mixed> $config 限流配置
     */
    public static function middleware(array $config, string $keyPrefix = ''): \Kode\Messaging\Contract\MiddlewareInterface
    {
        $limiter = self::create($config);
        $driver = strtolower((string) ($config['driver'] ?? 'token_bucket'));

        return str_starts_with($driver, 'sliding_window')
            ? SlidingWindowMiddleware::wrap($limiter, $keyPrefix)
            : TokenBucketMiddleware::wrap($limiter, $keyPrefix);
    }

    private static function resolveLimiterType(string $driver): LimiterType
    {
        return match (strtolower($driver)) {
            'token_bucket' => LimiterType::TOKEN_BUCKET,
            'sliding_window' => LimiterType::SLIDING_WINDOW,
            'sliding_window_counter' => LimiterType::SLIDING_WINDOW_COUNTER,
            'leaky_bucket' => LimiterType::LEAKY_BUCKET,
            'counter' => LimiterType::COUNTER,
            default => throw new MessagingException(
                sprintf(
                    '不支持的限流算法: %s（支持 token_bucket / sliding_window / sliding_window_counter / leaky_bucket / counter）',
                    $driver,
                ),
                500,
            ),
        };
    }

    private static function isWindowType(LimiterType $type): bool
    {
        return in_array($type, self::WINDOW_TYPES, true);
    }

    private static function resolveRedisMode(string $mode): RedisMode
    {
        return match (strtolower($mode)) {
            'sentinel' => RedisMode::SENTINEL,
            'cluster' => RedisMode::CLUSTER,
            default => RedisMode::STANDALONE,
        };
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function resolveStore(string $storeKey, array $opts): \Kode\Limiting\Store\StoreInterface
    {
        return match ($storeKey) {
            'memory' => new MemoryStore(),
            'apcu' => ApcuStore::create(
                (string) ($opts['prefix'] ?? 'kode:limiting:'),
            ),
            'memcached' => MemcachedStore::create(
                (string) ($opts['host'] ?? '127.0.0.1'),
                (int) ($opts['port'] ?? 11211),
            ),
            'pdo' => new PdoStore(
                new PDO(
                    (string) ($opts['dsn'] ?? 'sqlite::memory:'),
                    $opts['username'] ?? null,
                    $opts['password'] ?? null,
                ),
            ),
            'redis' => throw new MessagingException('resolveStore 不应处理 redis，请走 Limiter::redis()', 500),
            default => throw new MessagingException(
                \sprintf('不支持的限流存储: %s（支持 memory / redis / memcached / pdo / apcu）', $storeKey),
                500,
            ),
        };
    }
}
