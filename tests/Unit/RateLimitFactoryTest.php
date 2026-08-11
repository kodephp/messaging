<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\Store\ApcuStore;
use Kode\Messaging\Exception\MessagingException;
use Kode\Messaging\Middleware\RateLimit\RateLimitFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RateLimitFactory 单元测试
 *
 * 覆盖 kode/limiting 2.x 接入：
 *  - 5 种算法（token_bucket / sliding_window / sliding_window_counter / leaky_bucket / counter）
 *  - memory / pdo（sqlite 内存）/ apcu（按扩展守卫）存储
 *  - 非法 driver / store 抛 MessagingException
 *
 * 不覆盖 redis 存储（会立即建连，需真实 Redis 服务）。
 */
final class RateLimitFactoryTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function algorithmProvider(): array
    {
        return [
            ['token_bucket'],
            ['sliding_window'],
            ['sliding_window_counter'],
            ['leaky_bucket'],
            ['counter'],
        ];
    }

    #[DataProvider('algorithmProvider')]
    public function test_create_with_memory_store_for_each_algorithm(string $driver): void
    {
        $limiter = RateLimitFactory::create([
            'driver' => $driver,
            'capacity' => 5,
            'store' => 'memory',
        ]);

        $this->assertInstanceOf(RateLimiterInterface::class, $limiter);
        // 全新 key 首次请求应被放行
        $this->assertTrue($limiter->allow('unit:'.$driver.':fresh', 1));
    }

    public function test_pdo_store_builds_and_allows(): void
    {
        $limiter = RateLimitFactory::create([
            'driver' => 'token_bucket',
            'capacity' => 5,
            'store' => 'pdo',
            'store_opts' => ['dsn' => 'sqlite::memory:'],
        ]);

        $this->assertInstanceOf(RateLimiterInterface::class, $limiter);
        $this->assertTrue($limiter->allow('pdo:fresh', 1));
    }

    public function test_apcu_store_builds_when_available(): void
    {
        if (! ApcuStore::isAvailable()) {
            $this->markTestSkipped('APCu 扩展不可用，跳过 apcu 存储测试');
        }

        $limiter = RateLimitFactory::create([
            'driver' => 'token_bucket',
            'capacity' => 5,
            'store' => 'apcu',
            'store_opts' => ['prefix' => 'messaging:test:'],
        ]);

        $this->assertInstanceOf(RateLimiterInterface::class, $limiter);
        $this->assertTrue($limiter->allow('apcu:fresh', 1));
    }

    public function test_unknown_driver_throws(): void
    {
        $this->expectException(MessagingException::class);
        RateLimitFactory::create([
            'driver' => 'unknown_algo',
            'store' => 'memory',
        ]);
    }

    public function test_unknown_store_throws(): void
    {
        $this->expectException(MessagingException::class);
        RateLimitFactory::create([
            'driver' => 'token_bucket',
            'store' => 'nonexistent',
        ]);
    }

    public function test_empty_config_throws(): void
    {
        $this->expectException(MessagingException::class);
        RateLimitFactory::create([]);
    }

    public function test_middleware_wraps_algorithm_agnostic(): void
    {
        $mw = RateLimitFactory::middleware([
            'driver' => 'leaky_bucket',
            'capacity' => 5,
            'store' => 'memory',
        ], 'tenant-a:');

        $this->assertInstanceOf(\Kode\Messaging\Contract\MiddlewareInterface::class, $mw);
    }
}
