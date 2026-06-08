<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Exception\MessagingException;
use Kode\Messaging\Middleware\RateLimit\RateLimitFactory;
use Kode\Messaging\Middleware\RateLimit\SlidingWindowMiddleware;
use Kode\Messaging\Middleware\RateLimit\TokenBucketMiddleware;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    public function testTokenBucketMemoryAllowsBurstUpToCapacity(): void
    {
        $mw = TokenBucketMiddleware::memory(capacity: 5, refillRate: 0.001);

        $msg = $this->makeMessage('cid-1');
        for ($i = 0; $i < 5; $i++) {
            $result = $mw->process($msg, fn (MessageInterface $m) => $m);
            $this->assertInstanceOf(MessageInterface::class, $result);
        }
    }

    public function testTokenBucketMemoryBlocksWhenExhausted(): void
    {
        $mw = TokenBucketMiddleware::memory(capacity: 2, refillRate: 0.001);

        $msg = $this->makeMessage('cid-2');
        $mw->process($msg, fn (MessageInterface $m) => $m);
        $mw->process($msg, fn (MessageInterface $m) => $m);

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('限流触发（令牌桶）');
        $mw->process($msg, fn (MessageInterface $m) => $m);
    }

    public function testTokenBucketIsolatesBucketsByConnectionId(): void
    {
        $mw = TokenBucketMiddleware::memory(capacity: 1, refillRate: 0.001);

        $a = $this->makeMessage('cid-A');
        $b = $this->makeMessage('cid-B');

        $mw->process($a, fn (MessageInterface $m) => $m);
        // cid-A 已耗尽，cid-B 不应受影响
        $result = $mw->process($b, fn (MessageInterface $m) => $m);
        $this->assertInstanceOf(MessageInterface::class, $result);
    }

    public function testTokenBucketFallsBackToRemoteAddress(): void
    {
        $mw = TokenBucketMiddleware::memory(capacity: 1, refillRate: 0.001);
        $a = $this->makeMessage('', ['remote_address' => '1.2.3.4:5678']);
        $b = $this->makeMessage('', ['remote_address' => '5.6.7.8:1234']);

        $mw->process($a, fn (MessageInterface $m) => $m);
        $result = $mw->process($b, fn (MessageInterface $m) => $m);
        $this->assertInstanceOf(MessageInterface::class, $result);
    }

    public function testTokenBucketExceptionCarriesDiagnostics(): void
    {
        $mw = TokenBucketMiddleware::memory(capacity: 1, refillRate: 0.001);
        $msg = $this->makeMessage('cid-x');
        $mw->process($msg, fn (MessageInterface $m) => $m);

        try {
            $mw->process($msg, fn (MessageInterface $m) => $m);
            $this->fail('应该抛 MessagingException');
        } catch (MessagingException $e) {
            $this->assertSame(429, $e->getCode());
            $info = $e->context();
            $this->assertSame('token_bucket', $info['algorithm']);
            $this->assertSame(1, $info['capacity']);
            $this->assertSame(0.001, $info['refill_rate']);
            $this->assertArrayHasKey('wait_time', $info);
            $this->assertArrayHasKey('remaining', $info);
        }
    }

    public function testTokenBucketWrapAcceptsCustomLimiter(): void
    {
        $limiter = \Kode\Limiting\Limiter::tokenBucket(1, 0.001)->build();
        $mw = TokenBucketMiddleware::wrap($limiter, 'custom:');
        $msg = $this->makeMessage('cid-wrap');

        $mw->process($msg, fn (MessageInterface $m) => $m);

        $this->expectException(MessagingException::class);
        $mw->process($msg, fn (MessageInterface $m) => $m);
    }

    public function testSlidingWindowMemoryBlocksAtCapacity(): void
    {
        $mw = SlidingWindowMiddleware::memory(capacity: 3, windowSize: 60.0);
        $msg = $this->makeMessage('cid-sw');

        for ($i = 0; $i < 3; $i++) {
            $result = $mw->process($msg, fn (MessageInterface $m) => $m);
            $this->assertInstanceOf(MessageInterface::class, $result);
        }

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('限流触发（滑动窗口）');
        $mw->process($msg, fn (MessageInterface $m) => $m);
    }

    public function testSlidingWindowExceptionCarriesAlgorithmInfo(): void
    {
        $mw = SlidingWindowMiddleware::memory(capacity: 1, windowSize: 1.0);
        $msg = $this->makeMessage('cid-sw-2');
        $mw->process($msg, fn (MessageInterface $m) => $m);

        try {
            $mw->process($msg, fn (MessageInterface $m) => $m);
            $this->fail('应该抛 MessagingException');
        } catch (MessagingException $e) {
            $info = $e->context();
            $this->assertSame('sliding_window', $info['algorithm']);
            $this->assertSame(1, $info['capacity']);
            $this->assertSame(1.0, $info['window_size']);
        }
    }

    public function testFactoryCreatesTokenBucket(): void
    {
        $limiter = RateLimitFactory::create([
            'driver'   => 'token_bucket',
            'capacity' => 10,
            'rate'     => 5.0,
            'store'    => 'memory',
        ]);
        $this->assertInstanceOf(\Kode\Limiting\Algorithm\RateLimiterInterface::class, $limiter);
        $this->assertTrue($limiter->allow('test:1'));
    }

    public function testFactoryCreatesSlidingWindow(): void
    {
        $limiter = RateLimitFactory::create([
            'driver'   => 'sliding_window',
            'capacity' => 10,
            'window'   => 1.0,
            'store'    => 'memory',
        ]);
        $this->assertInstanceOf(\Kode\Limiting\Algorithm\RateLimiterInterface::class, $limiter);
        $this->assertTrue($limiter->allow('test:1'));
    }

    public function testFactoryRejectsUnknownDriver(): void
    {
        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('不支持的限流算法');
        RateLimitFactory::create([
            'driver'   => 'unknown_algo',
            'capacity' => 10,
            'store'    => 'memory',
        ]);
    }

    public function testFactoryRejectsEmptyConfig(): void
    {
        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('限流配置不能为空');
        RateLimitFactory::create([]);
    }

    public function testFactoryMiddlewareReturnsCorrectType(): void
    {
        $tb = RateLimitFactory::middleware([
            'driver'   => 'token_bucket',
            'capacity' => 10,
            'rate'     => 1.0,
            'store'    => 'memory',
        ]);
        $this->assertInstanceOf(TokenBucketMiddleware::class, $tb);

        $sw = RateLimitFactory::middleware([
            'driver'   => 'sliding_window',
            'capacity' => 10,
            'window'   => 1.0,
            'store'    => 'memory',
        ]);
        $this->assertInstanceOf(SlidingWindowMiddleware::class, $sw);
    }

    /**
     * 构造一个带 context 的 Message 实例用于测试。
     */
    private function makeMessage(string $connectionId, array $extraContext = []): MessageInterface
    {
        return \Kode\Messaging\Message\Message::fromRaw(
            'test-payload',
            'rtmp',
            event: 'test',
            context: array_merge(
                $connectionId !== '' ? ['connection_id' => $connectionId] : [],
                $extraContext,
            ),
        );
    }
}
