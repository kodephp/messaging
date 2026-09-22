<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Messaging;
use Kode\Messaging\PubSub\Bus;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * 总线共享与订阅引用计数测试。
 *
 * 覆盖两条真实故障：
 *  - pubsub() 每次 new 实例 → 订阅跟着对象一起蒸发，publish 静默零投递
 *    （框架侧 messaging()->bus() 就是这么逐次取总线的）；
 *  - 同一 topic 订阅 N 次 → 底层注册 N 份 → 一条消息分发 N 倍；
 *    任一订阅者退订就把同 topic 其他订阅者一起断供。
 */
final class BusSharingTest extends TestCase
{
    protected function setUp(): void
    {
        Messaging::configure(['pubsub' => ['default' => 'memory']]);
        Messaging::resetBuses();
    }

    protected function tearDown(): void
    {
        Messaging::resetBuses();
        Messaging::configure([]);
    }

    // ===================== 实例共享 =====================

    public function test_同参数多次取总线返回同一实例(): void
    {
        $this->assertSame(Messaging::pubsub('memory'), Messaging::pubsub('memory'));
        $this->assertSame(Messaging::pubsub(), Messaging::pubsub());
    }

    public function test_配置不同则是不同实例(): void
    {
        $a = Messaging::pubsub('memory', ['scope' => 'a']);
        $b = Messaging::pubsub('memory', ['scope' => 'b']);

        $this->assertNotSame($a, $b);
    }

    public function test_键序不同的同一份配置仍是同一实例(): void
    {
        $a = Messaging::pubsub('memory', ['pool' => ['size' => 4, 'name' => 'bus'], 'scope' => 'k']);
        $b = Messaging::pubsub('memory', ['scope' => 'k', 'pool' => ['name' => 'bus', 'size' => 4]]);

        $this->assertSame($a, $b);
    }

    public function test_含闭包或对象的配置不缓存也不并桶(): void
    {
        // json_encode 会把闭包/对象静默压成 {}，靠它做指纹会把两份不同意图的配置并成一条总线
        $h1 = Messaging::pubsub('memory', ['on_ready' => static fn() => 'a']);
        $h2 = Messaging::pubsub('memory', ['on_ready' => static fn() => 'b']);
        $o1 = Messaging::pubsub('memory', ['serializer' => new stdClass()]);
        $o2 = Messaging::pubsub('memory', ['serializer' => new CountingBus()]);

        $this->assertNotSame($h1, $h2);
        $this->assertNotSame($o1, $o2);
        $this->assertNotSame($h1, Messaging::pubsub('memory'));
    }

    public function test_订阅在一次调用里另一个实例看不存在(): void
    {
        // 反向兜底：真正隔离的实例（自行 new）不应被缓存池串到
        $direct = new \Kode\Messaging\PubSub\MemoryBus();
        $direct->subscribe('t/direct', static fn() => null);

        $this->assertSame(0, Messaging::pubsub('memory')->subscriberCount());
        $this->assertSame(1, $direct->subscriberCount());
    }

    // ===================== 跨调用投递（复现原故障）=====================

    public function test_订阅与发布分处两次查询时仍能投递(): void
    {
        $hits = [];

        // 这三次 messaging()->bus() 形状，对应框架 Messenger 里 subscribe()/publish() 各自取总线
        Messaging::pubsub('memory')->subscribe('orders:created', static function (array $payload) use (&$hits): void {
            $hits[] = $payload;
        });

        Messaging::pubsub('memory')->publish('orders:created', ['id' => 7]);

        $this->assertCount(1, $hits, '订阅应跨调用存活；修复前这里恒为 0 条');
        $this->assertSame(['id' => 7], $hits[0]);
    }

    public function test_reset_buses_后旧订阅一并作废(): void
    {
        Messaging::pubsub('memory')->subscribe('t/a', static fn() => null);
        $this->assertSame(1, Messaging::pubsub('memory')->subscriberCount());

        Messaging::resetBuses();

        $this->assertSame(0, Messaging::pubsub('memory')->subscriberCount());
    }

    // ===================== 底层注册引用计数 =====================

    public function test_同主题重复订阅底层只注册一次(): void
    {
        $bus = new CountingBus();

        $bus->subscribe('a/b', static fn() => null);
        $bus->subscribe('a/b', static fn() => null);
        $bus->subscribe('a/b', static fn() => null);

        $this->assertSame(['a/b' => 1], $bus->registered, '底层注册应按 topic 去重');
        $this->assertSame(3, $bus->subscriberCount());
        $this->assertSame(1, $bus->topicCount());
    }

    public function test_一条消息每个订阅者恰好收到一次(): void
    {
        $bus = new CountingBus();
        $seen = [];

        $bus->subscribe('a/b', static function (array $p) use (&$seen): void {
            $seen['x'][] = $p;
        });
        $bus->subscribe('a/b', static function (array $p) use (&$seen): void {
            $seen['y'][] = $p;
        });

        $bus->publish('a/b', ['n' => 1]);

        $this->assertCount(1, $seen['x']);
        $this->assertCount(1, $seen['y']);
    }

    public function test_最后一个订阅者退订才注销底层(): void
    {
        $bus = new CountingBus();

        $first = $bus->subscribe('a/b', static fn() => null);
        $second = $bus->subscribe('a/b', static fn() => null);
        $other = $bus->subscribe('c/d', static fn() => null);

        $bus->unsubscribe($first);
        $this->assertSame([], $bus->unregistered, '仍有同 topic 订阅者时不该拆底层');

        $bus->unsubscribe($second);
        $this->assertSame(['a/b'], array_keys($bus->unregistered));
        $this->assertSame(1, $bus->subscriberCount());

        $bus->unsubscribe($other);
        $this->assertSame(['a/b', 'c/d'], array_keys($bus->unregistered));
        $this->assertSame(0, $bus->subscriberCount());
    }

    public function test_未知订阅_id_退订不报错也不影响计数(): void
    {
        $bus = new CountingBus();
        $kept = $bus->subscribe('a/b', static fn() => null);

        $bus->unsubscribe('sub-not-exist');

        $this->assertSame([], $bus->unregistered);
        $this->assertSame(1, $bus->subscriberCount());

        $bus->unsubscribe($kept);
        $this->assertSame(['a/b'], array_keys($bus->unregistered));
    }

    public function test_通配订阅仍按各自匹配投递(): void
    {
        $bus = new CountingBus();
        $hits = [];

        $bus->subscribe('a/*', static function (array $p) use (&$hits): void {
            $hits['single'][] = $p;
        });
        $bus->subscribe('a/#', static function (array $p) use (&$hits): void {
            $hits['multi'][] = $p;
        });
        $bus->subscribe('a/b/c', static function (array $p) use (&$hits): void {
            $hits['exact'][] = $p;
        });

        $bus->publish('a/b/c', ['n' => 1]);

        $this->assertArrayNotHasKey('single', $hits, '* 不跨级');
        $this->assertCount(1, $hits['multi']);
        $this->assertCount(1, $hits['exact']);
        $this->assertSame(['a/*' => 1, 'a/#' => 1, 'a/b/c' => 1], $bus->registered);
    }
}

/**
 * 记录底层注册/注销次数的测试总线（不触任何外部系统）。
 */
final class CountingBus extends Bus
{
    /** @var array<string, int> topic => 期望的底层注册次数（按调用顺序累加） */
    public array $registered = [];

    /** @var array<string, int> */
    public array $unregistered = [];

    public function driver(): string
    {
        return 'counting';
    }

    public function publish(string $topic, array $payload, array $options = []): void
    {
        $this->dispatch($topic, $payload);
    }

    protected function onSubscribe(string $topic, array $options): void
    {
        $this->registered[$topic] = ($this->registered[$topic] ?? 0) + 1;
    }

    protected function onUnsubscribe(string $topic): void
    {
        $this->unregistered[$topic] = ($this->unregistered[$topic] ?? 0) + 1;
    }
}
