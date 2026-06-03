<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Feature;

use Kode\Messaging\Adapter\LongPolling\Hub;
use PHPUnit\Framework\TestCase;

/**
 * LongPolling 集成测试（Hub 模式）
 *
 * 模拟：
 *  - N 个客户端订阅 topic
 *  - 业务推送 payload
 *  - 每个客户端接收一次
 */
final class LongPollingPushTest extends TestCase
{
    public function testHubPushDeliversToSubscribers(): void
    {
        $hub = new Hub();
        $received = [];
        $hub->subscribe('orders', function ($payload) use (&$received) {
            $received[] = $payload;
        });
        $hub->subscribe('orders', function ($payload) use (&$received) {
            $received[] = ['b' => $payload];
        });
        $hub->subscribe('users', function ($payload) use (&$received) {
            $received[] = $payload;
        });

        $this->assertSame(2, $hub->push('orders', 'X'));
        $this->assertSame(1, $hub->push('users', 'Y'));
        $this->assertSame(0, $hub->push('nonexistent', 'Z'));

        $this->assertCount(3, $received);
    }

    public function testHubCrossProcessDispatch(): void
    {
        $hubA = new Hub(Hub::DRIVER_CHANNEL);
        $hubB = new Hub(Hub::DRIVER_CHANNEL);

        // 模拟外部分发器：把推送写入"总线"，另一个 hub 在其推时收到
        $bus = [];
        $hubA->setDispatcher(function (string $topic, $payload) use (&$bus) {
            $bus[] = ['topic' => $topic, 'payload' => $payload];
        });
        $hubB->setDispatcher(function (string $topic, $payload) use (&$bus) {
            $bus[] = ['fromB' => true, 'topic' => $topic, 'payload' => $payload];
        });

        $localReceived = 0;
        $hubA->subscribe('sync', function () use (&$localReceived) { $localReceived++; });
        $hubB->subscribe('sync', function () use (&$localReceived) { $localReceived++; });

        $hubA->push('sync', 'data');
        $hubB->push('sync', 'data');

        $this->assertSame(2, $localReceived);   // 各自本地回调
        $this->assertCount(2, $bus);             // 各自跨进程分发
    }
}
