<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Mqtt\MqttConnection;
use PHPUnit\Framework\TestCase;

/**
 * MQTT 客户端连接：本地主题回调分发单元测试
 *
 * 回归点：旧实现用 preg_quote + `*` 作为单级通配符，
 * 而 MQTT 标准通配符是 `+`（会被 preg_quote 转义成字面量），
 * 导致以 `a/+/b` 订阅的客户端回调永远不触发。
 */
final class MqttClientTopicTest extends TestCase
{
    private function conn(): MqttConnection
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        return new MqttConnection('c1', 'mqtt', '127.0.0.1:1883', $stream);
    }

    public function testPlusWildcardHandlerIsInvoked(): void
    {
        $received = [];
        $conn = $this->conn();
        $conn->addTopicHandler('sensors/+/temp', function (string $topic, string $payload) use (&$received): void {
            $received[] = $topic . '=' . $payload;
        });

        $conn->dispatchPublish('sensors/room1/temp', '25', 0, false, 0);
        $conn->dispatchPublish('sensors/room2/temp', '26', 0, false, 0);
        $conn->dispatchPublish('sensors/room1/humidity', '60', 0, false, 0);

        $this->assertSame(['sensors/room1/temp=25', 'sensors/room2/temp=26'], $received);
    }

    public function testHashWildcardHandlerIsInvoked(): void
    {
        $count = 0;
        $conn = $this->conn();
        $conn->addTopicHandler('sensors/#', function () use (&$count): void {
            $count++;
        });

        $conn->dispatchPublish('sensors', 'x', 0, false, 0);
        $conn->dispatchPublish('sensors/a/b/c', 'y', 0, false, 0);
        $conn->dispatchPublish('other/a', 'z', 0, false, 0);

        $this->assertSame(2, $count);
    }

    public function testExactTopicHandler(): void
    {
        $hit = false;
        $conn = $this->conn();
        $conn->addTopicHandler('a/b', function () use (&$hit): void {
            $hit = true;
        });

        $conn->dispatchPublish('a/b', '1', 0, false, 0);
        $this->assertTrue($hit);
    }

    public function testHandlerExceptionIsIsolated(): void
    {
        $second = false;
        $conn = $this->conn();
        $conn->addTopicHandler('a/#', function (): void {
            throw new \RuntimeException('boom');
        });
        $conn->addTopicHandler('a/b', function () use (&$second): void {
            $second = true;
        });

        $conn->dispatchPublish('a/b', '1', 0, false, 0);
        $this->assertTrue($second);
    }

    public function testAckHandlerFiresOnceAndIsRemoved(): void
    {
        $calls = 0;
        $conn = $this->conn();
        $conn->onAck(7, function () use (&$calls): void {
            $calls++;
        });

        $conn->dispatchAck(7, ['type' => 'puback']);
        $conn->dispatchAck(7, ['type' => 'puback']);
        $this->assertSame(1, $calls);
    }
}
