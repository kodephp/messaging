<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Mqtt\Packet\Connect;
use Kode\Messaging\Adapter\Mqtt\Packet\Publish;
use Kode\Messaging\Adapter\Mqtt\Packet\Subscribe;
use Kode\Messaging\Adapter\Sse\Formatter;
use Kode\Messaging\Message\Message as Msg;
use PHPUnit\Framework\TestCase;

final class PacketTest extends TestCase
{
    public function test_mqtt_connect_encode(): void
    {
        $packet = Connect::encode(
            clientId: 'client-001',
            username: 'user',
            password: 'pass',
            keepalive: 60,
            cleanSession: true,
            version: '3.1.1',
        );
        // 包类型 1 (CONNECT) << 4 = 0x10
        $this->assertSame(0x10, ord($packet[0]));
        // 包含 "MQTT"
        $this->assertStringContainsString('MQTT', $packet);
        $this->assertStringContainsString('client-001', $packet);
    }

    public function test_mqtt_publish_encode(): void
    {
        $bytes = Publish::encode('sensors/temp', '23.5', qos: 1);
        // 0x32 (PUBLISH + QoS 1)
        $this->assertSame(0x32, ord($bytes[0]));
        $this->assertStringContainsString('sensors/temp', $bytes);
        $this->assertStringContainsString('23.5', $bytes);
    }

    public function test_mqtt_subscribe_encode(): void
    {
        $bytes = Subscribe::encode(1, [['topic' => 'sensors/#', 'qos' => 1]]);
        // 0x82 (SUBSCRIBE + 0x02)
        $this->assertSame(0x82, ord($bytes[0]));
        $this->assertStringContainsString('sensors/#', $bytes);
    }

    public function test_sse_format(): void
    {
        $text = Formatter::format('{"time":123}', event: 'tick', id: 'evt-1', retry: 3000);
        $this->assertStringContainsString("event: tick\n", $text);
        $this->assertStringContainsString("id: evt-1\n", $text);
        $this->assertStringContainsString("retry: 3000\n", $text);
        $this->assertStringContainsString("data: {\"time\":123}\n", $text);
        $this->assertStringEndsWith("\n\n", $text);
    }

    public function test_sse_from_message(): void
    {
        $msg = Msg::of(['time' => 123], 'sse', event: 'tick');
        $text = Formatter::fromMessage($msg);
        $this->assertStringContainsString('event: tick', $text);
        $this->assertStringContainsString('data:', $text);
    }
}
