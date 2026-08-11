<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Message\Message;
use Kode\Messaging\Router\Match\PrefixMatcher;
use Kode\Messaging\Router\Router;
use Kode\Messaging\Tests\Unit\_fixtures\FakeConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * 消息路由器单元测试
 *
 * 覆盖：
 *  - 精确 / 前缀(*) / 多级(#) / 正则 四类路由
 *  - 优先级：精确 > 通配 > 正则
 *  - Matcher 注册期编译后跨多次 dispatch 复用且结果稳定
 *  - dispatch 返回是否命中；event 缺失时回退到 topic
 *  - fallback / onError / off / has / patterns / count
 */
final class RouterTest extends TestCase
{
    private function conn(): FakeConnection
    {
        return new FakeConnection('c1', 'ws', '127.0.0.1:1234');
    }

    private function msg(?string $event, ?string $topic = null): Message
    {
        return Message::of('payload', 'ws', event: $event, topic: $topic);
    }

    public function test_exact_match(): void
    {
        $hit = [];
        $router = (new Router())->on('chat.send', function () use (&$hit): void {
            $hit[] = 'exact';
        });

        $this->assertTrue($router->dispatch($this->conn(), $this->msg('chat.send')));
        $this->assertSame(['exact'], $hit);
    }

    public function test_prefix_wildcard_match(): void
    {
        $hit = [];
        $router = (new Router())->on('chat.*', function () use (&$hit): void {
            $hit[] = 'prefix';
        });

        $this->assertTrue($router->dispatch($this->conn(), $this->msg('chat.room.42')));
        $this->assertFalse($router->dispatch($this->conn(), $this->msg('sys.ping')));
        $this->assertSame(['prefix'], $hit);
    }

    public function test_hash_wildcard_match(): void
    {
        $hit = 0;
        $router = (new Router())->on('sensors/#', function () use (&$hit): void {
            $hit++;
        });

        $this->assertTrue($router->dispatch($this->conn(), $this->msg('sensors/a/b')));
        $this->assertTrue($router->dispatch($this->conn(), $this->msg('sensors/')));
        $this->assertSame(2, $hit);
    }

    public function test_regex_match(): void
    {
        $hit = [];
        $router = (new Router())->on('/^order\.\d+$/', function () use (&$hit): void {
            $hit[] = 'regex';
        });

        $this->assertTrue($router->dispatch($this->conn(), $this->msg('order.1001')));
        $this->assertFalse($router->dispatch($this->conn(), $this->msg('order.abc')));
        $this->assertSame(['regex'], $hit);
    }

    public function test_exact_wins_over_wildcard(): void
    {
        $order = [];
        $router = (new Router())
            ->on('chat.*', function () use (&$order): void {
                $order[] = 'wildcard';
            })
            ->on('chat.send', function () use (&$order): void {
                $order[] = 'exact';
            });

        $router->dispatch($this->conn(), $this->msg('chat.send'));
        $this->assertSame(['exact'], $order);
    }

    public function test_compiled_matcher_reused_across_dispatches(): void
    {
        $count = 0;
        $router = (new Router())->on('a.*', function () use (&$count): void {
            $count++;
        });

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($router->dispatch($this->conn(), $this->msg('a.'.$i)));
        }
        $this->assertSame(50, $count);
    }

    public function test_falls_back_to_topic_when_event_missing(): void
    {
        $hit = false;
        $router = (new Router())->on('orders/created', function () use (&$hit): void {
            $hit = true;
        });

        $this->assertTrue($router->dispatch($this->conn(), $this->msg(null, 'orders/created')));
        $this->assertTrue($hit);
    }

    public function test_empty_key_is_not_dispatched(): void
    {
        $router = (new Router())->on('x', function (): void {
            self::fail('不应被调用');
        });
        $this->assertFalse($router->dispatch($this->conn(), $this->msg(null, null)));
    }

    public function test_fallback_handler(): void
    {
        $missed = [];
        $router = (new Router())
            ->on('known', function (): void {})
            ->fallback(function ($conn, $message) use (&$missed): void {
                $missed[] = $message->event();
            });

        // 兜底被调用，但不算命中
        $this->assertFalse($router->dispatch($this->conn(), $this->msg('unknown')));
        $this->assertSame(['unknown'], $missed);

        $router->fallback(null);
        $this->assertFalse($router->dispatch($this->conn(), $this->msg('unknown2')));
        $this->assertSame(['unknown'], $missed);
    }

    public function test_handler_exception_is_isolated_and_reported_to_error_handler(): void
    {
        $errors = [];
        $router = (new Router())
            ->on('boom', function (): void {
                throw new RuntimeException('handler failed');
            })
            ->onError(function (Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            });

        // 不抛出，仍视为命中
        $this->assertTrue($router->dispatch($this->conn(), $this->msg('boom')));
        $this->assertSame(['handler failed'], $errors);
    }

    public function test_handler_exception_silent_without_error_handler(): void
    {
        $router = (new Router())->on('boom', function (): void {
            throw new RuntimeException('x');
        });
        $this->assertTrue($router->dispatch($this->conn(), $this->msg('boom')));
    }

    public function test_off_removes_route(): void
    {
        $router = (new Router())
            ->on('a', fn() => null)
            ->on('b.*', fn() => null)
            ->on('/^c$/', fn() => null);

        $this->assertSame(3, $router->count());
        $this->assertTrue($router->has('b.*'));

        $router->off('b.*');
        $this->assertFalse($router->has('b.*'));
        $this->assertSame(2, $router->count());
        $this->assertFalse($router->dispatch($this->conn(), $this->msg('b.1')));
    }

    public function test_patterns_listing(): void
    {
        $router = (new Router())
            ->on('a', fn() => null)
            ->on('b.*', fn() => null)
            ->on('/^c$/', fn() => null);

        $this->assertSame(['a', 'b.*', '/^c$/'], $router->patterns());
    }

    public function test_prefix_matcher_compiles_once_and_exposes_pattern(): void
    {
        $matcher = new PrefixMatcher('chat.*');
        $this->assertSame('chat.*', $matcher->pattern());
        $this->assertTrue($matcher->match('chat.a.b'));
        $this->assertTrue($matcher->match('chat.a.b'));
        $this->assertFalse($matcher->match('chatx'));
    }
}
