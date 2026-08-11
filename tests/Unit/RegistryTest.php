<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Adapter\Udp\Client as UdpClient;
use Kode\Messaging\Messaging;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Registry::reset();
    }

    public function test_register_and_find(): void
    {
        Registry::register('foo', UdpClient::class);
        $this->assertSame(UdpClient::class, Registry::find('foo'));
    }

    public function test_scheme_is_case_insensitive(): void
    {
        Registry::register('WS', UdpClient::class);
        $this->assertSame(UdpClient::class, Registry::find('ws'));
        $this->assertSame(UdpClient::class, Registry::find('WS'));
    }

    public function test_normalize_scheme(): void
    {
        $this->assertSame('ws', Messaging::normalizeScheme('wss'));
        $this->assertSame('ws', Messaging::normalizeScheme('WebSocket'));
        $this->assertSame('mqtt', Messaging::normalizeScheme('mqtts'));
        $this->assertSame('sse', Messaging::normalizeScheme('eventsource'));
        $this->assertSame('udp', Messaging::normalizeScheme('dgram'));
    }

    public function test_parse_url(): void
    {
        $info = Messaging::parseUrl('wss://example.com:443/path?token=abc');
        $this->assertSame('ws', $info['scheme']);
        $this->assertSame('example.com', $info['host']);
        $this->assertSame(443, $info['port']);
        $this->assertTrue($info['tls']);
        $this->assertSame('abc', $info['query']['token'] ?? null);
    }

    public function test_default_port(): void
    {
        $this->assertSame(80, Messaging::defaultPort('ws'));
        $this->assertSame(443, Messaging::defaultPort('ws', true));
        $this->assertSame(1883, Messaging::defaultPort('mqtt'));
        $this->assertSame(8883, Messaging::defaultPort('mqtt', true));
        $this->assertSame(4222, Messaging::defaultPort('nats'));
        $this->assertSame(61613, Messaging::defaultPort('stomp'));
        $this->assertSame(50051, Messaging::defaultPort('grpc'));
        $this->assertSame(1935, Messaging::defaultPort('rtmp'));
    }

    public function test_normalize_new_schemes(): void
    {
        $this->assertSame('nats', Messaging::normalizeScheme('NATS'));
        $this->assertSame('stomp', Messaging::normalizeScheme('stomps'));
        $this->assertSame('grpc', Messaging::normalizeScheme('grpc-web'));
        $this->assertSame('webtransport', Messaging::normalizeScheme('wt'));
        $this->assertSame('rtmp', Messaging::normalizeScheme('rtmps'));
    }
}
