<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Contract\AcknowledgeInterface;
use Kode\Messaging\PubSub\MemoryBus;
use PHPUnit\Framework\TestCase;

/**
 * Pub/Sub 总线单元测试
 *
 * 覆盖：
 *  - 精确主题匹配
 *  - 单级通配符 *（不匹配斜杠）
 *  - 多级通配符 #（跨级匹配）
 *  - 主题正则编译缓存（多次匹配结果一致）
 *  - 分发投递（单/多订阅者、通配符订阅）
 *  - 取消订阅
 *  - subscriberCount() / topicCount() 可观测性
 */
final class PubSubBusTest extends TestCase
{
    // ===================== 精确匹配 =====================

    public function testExactMatch(): void
    {
        $bus = new MemoryBus();

        $this->assertTrue($bus->match('a/b/c', 'a/b/c'));
        $this->assertFalse($bus->match('a/b/c', 'a/b/d'));
        $this->assertFalse($bus->match('a/b/c', 'a/b'));
    }

    // ===================== 单级通配符 * =====================

    public function testSingleLevelWildcard(): void
    {
        $bus = new MemoryBus();

        // * 匹配单段（不含斜杠）
        $this->assertTrue($bus->match('a/b/c', 'a/b/*'));
        $this->assertTrue($bus->match('a/x/c', 'a/*/c'));
        $this->assertTrue($bus->match('a/b', 'a/*'));

        // * 不能跨级匹配斜杠
        $this->assertFalse($bus->match('a/b/c/d', 'a/b/*'));
        $this->assertFalse($bus->match('a/b/c', 'a/*'));
    }

    // ===================== 多级通配符 # =====================

    public function testMultiLevelWildcard(): void
    {
        $bus = new MemoryBus();

        // # 跨级匹配（实现语义：a/# 编译为 a/.*，匹配 a/ 之后的任意层级）
        $this->assertTrue($bus->match('a/b/c/d', 'a/#'));
        $this->assertTrue($bus->match('a/b/c', 'a/#'));
        $this->assertTrue($bus->match('a/b', 'a/#'));

        // 与 MQTT 标准不同：本实现 a/# 不匹配裸父级 a（需 a/ 前缀）
        $this->assertFalse($bus->match('a', 'a/#'));

        // 不匹配不同前缀
        $this->assertFalse($bus->match('x/y/z', 'a/#'));
        $this->assertFalse($bus->match('a/b/c', 'x/#'));
    }

    // ===================== 正则编译缓存 =====================

    public function testPatternCacheConsistency(): void
    {
        $bus = new MemoryBus();

        // 同一 pattern 多次匹配，结果应一致（缓存命中不破坏语义）
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($bus->match('sensor/room1/temp', 'sensor/#'));
            $this->assertTrue($bus->match('sensor/room1', 'sensor/*'));
            $this->assertFalse($bus->match('sensor/room1/x/y', 'sensor/*'));
        }
    }

    // ===================== 分发投递 =====================

    public function testDispatchDeliversToExactSubscriber(): void
    {
        $bus = new MemoryBus();
        $received = [];

        $bus->subscribe('news/sports', function (array $payload, AcknowledgeInterface $ack) use (&$received): void {
            $received[] = $payload;
        });

        $bus->publish('news/sports', ['title' => 'goal']);
        $bus->publish('news/sports', ['title' => 'foul']);

        $this->assertSame([
            ['title' => 'goal'],
            ['title' => 'foul'],
        ], $received);
    }

    public function testDispatchDeliversToAllMatchingSubscribers(): void
    {
        $bus = new MemoryBus();
        $hits = 0;

        $bus->subscribe('events/#', function (array $payload, AcknowledgeInterface $ack) use (&$hits): void {
            $hits++;
        });
        $bus->subscribe('events/created', function (array $payload, AcknowledgeInterface $ack) use (&$hits): void {
            $hits++;
        });

        // 两条订阅都匹配 events/created
        $bus->publish('events/created', ['id' => 1]);

        $this->assertSame(2, $hits);
    }

    public function testWildcardSubscriberOnlyReceivesMatchingTopics(): void
    {
        $bus = new MemoryBus();
        $received = [];

        $bus->subscribe('sensor/#', function (array $payload, AcknowledgeInterface $ack) use (&$received): void {
            $received[] = $payload;
        });

        $bus->publish('sensor/temp/1', ['v' => 20]);
        $bus->publish('sensor/humidity', ['v' => 60]);
        $bus->publish('other/topic', ['v' => 999]);

        $this->assertSame([
            ['v' => 20],
            ['v' => 60],
        ], $received);
    }

    public function testHandlerErrorIsSwallowed(): void
    {
        $bus = new MemoryBus();
        $safe = [];

        $bus->subscribe('topic', function (array $payload, AcknowledgeInterface $ack): void {
            throw new \RuntimeException('boom');
        });
        $bus->subscribe('topic', function (array $payload, AcknowledgeInterface $ack) use (&$safe): void {
            $safe[] = $payload;
        });

        // 第一个 handler 抛错不应阻断第二个
        $bus->publish('topic', ['ok' => true]);

        $this->assertSame([['ok' => true]], $safe);
    }

    // ===================== 取消订阅 =====================

    public function testUnsubscribeStopsDelivery(): void
    {
        $bus = new MemoryBus();
        $received = [];

        $id = $bus->subscribe('a/b', function (array $payload, AcknowledgeInterface $ack) use (&$received): void {
            $received[] = $payload;
        });

        $bus->publish('a/b', ['n' => 1]);
        $this->assertSame(1, $bus->subscriberCount());

        $bus->unsubscribe($id);
        $bus->publish('a/b', ['n' => 2]);

        $this->assertSame([['n' => 1]], $received);
        $this->assertSame(0, $bus->subscriberCount());
    }

    public function testUnsubscribeUnknownIdIsNoop(): void
    {
        $bus = new MemoryBus();
        $bus->unsubscribe('non-existent-id');
        $this->assertSame(0, $bus->subscriberCount());
    }

    // ===================== 可观测性 =====================

    public function testSubscriberAndTopicCounts(): void
    {
        $bus = new MemoryBus();

        $bus->subscribe('a', function (array $p, AcknowledgeInterface $a): void {});
        $bus->subscribe('a', function (array $p, AcknowledgeInterface $a): void {});
        $bus->subscribe('b', function (array $p, AcknowledgeInterface $a): void {});

        // 3 个订阅者，2 个去重主题
        $this->assertSame(3, $bus->subscriberCount());
        $this->assertSame(2, $bus->topicCount());
    }

    public function testTopicCountEmptyBus(): void
    {
        $bus = new MemoryBus();
        $this->assertSame(0, $bus->topicCount());
        $this->assertSame(0, $bus->subscriberCount());
    }
}
