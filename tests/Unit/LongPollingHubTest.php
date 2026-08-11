<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\LongPolling\Hub;
use PHPUnit\Framework\TestCase;

final class LongPollingHubTest extends TestCase
{
    public function test_singleton(): void
    {
        $a = Hub::singleton();
        $b = Hub::singleton();
        $this->assertSame($a, $b);

        $c = new Hub(Hub::DRIVER_REDIS);
        Hub::setSingleton($c);
        $this->assertSame($c, Hub::singleton());

        // 复位
        Hub::setSingleton(new Hub());
    }

    public function test_subscribe_and_push(): void
    {
        $hub = new Hub();
        $received = [];
        $hub->subscribe('orders', function ($payload) use (&$received): void {
            $received[] = $payload;
        });

        $this->assertSame(1, $hub->push('orders', ['id' => 1]));
        $this->assertSame(1, $hub->push('orders', ['id' => 2]));
        $this->assertCount(2, $received);
        $this->assertSame(['id' => 1], $received[0]);
    }

    public function test_multiple_subscribers(): void
    {
        $hub = new Hub();
        $count1 = 0;
        $count2 = 0;
        $hub->subscribe('topic', function () use (&$count1): void {
            $count1++;
        });
        $hub->subscribe('topic', function () use (&$count2): void {
            $count2++;
        });

        $hub->push('topic', 'x');
        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2);
    }

    public function test_unsubscribe(): void
    {
        $hub = new Hub();
        $count = 0;
        $cb = function () use (&$count): void {
            $count++;
        };
        $hub->subscribe('orders', $cb);
        $hub->unsubscribe('orders', $cb);
        $hub->push('orders', 'x');
        $this->assertSame(0, $count);
    }

    public function test_unsubscribe_topic(): void
    {
        $hub = new Hub();
        $hub->subscribe('a', fn() => 1);
        $hub->subscribe('b', fn() => 1);
        $this->assertSame(1, $hub->topicCount('a'));
        $hub->unsubscribe('a');
        $this->assertSame(0, $hub->topicCount('a'));
        $this->assertSame(1, $hub->topicCount('b'));
    }

    public function test_dispatcher(): void
    {
        $hub = new Hub(Hub::DRIVER_CHANNEL);
        /** @var null|array{topic:string, payload:mixed} $dispatched */
        $dispatched = null;
        $hub->setDispatcher(function (string $topic, $payload) use (&$dispatched): void {
            $dispatched = ['topic' => $topic, 'payload' => $payload];
        });
        $hub->push('cross-node', ['data' => 1]);
        $this->assertNotNull($dispatched);
        $this->assertSame('cross-node', $dispatched['topic']);
        $this->assertSame(['data' => 1], $dispatched['payload']);
    }

    public function test_total_subscribers(): void
    {
        $hub = new Hub();
        $hub->subscribe('a', fn() => 1);
        $hub->subscribe('a', fn() => 1);
        $hub->subscribe('b', fn() => 1);
        $this->assertSame(3, $hub->totalSubscribers());
    }

    public function test_topics(): void
    {
        $hub = new Hub();
        $hub->subscribe('a', fn() => 1);
        $hub->subscribe('b', fn() => 1);
        $this->assertSame(['a', 'b'], $hub->topics());
    }
}
