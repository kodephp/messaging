<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Messaging;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Registry::reset();
    }

    public function testRegisterAndFind(): void
    {
        Registry::register('foo', \stdClass::class);
        $this->assertSame(\stdClass::class, Registry::find('foo'));
    }

    public function testSchemeIsCaseInsensitive(): void
    {
        Registry::register('WS', \stdClass::class);
        $this->assertSame(\stdClass::class, Registry::find('ws'));
        $this->assertSame(\stdClass::class, Registry::find('WS'));
    }

    public function testNormalizeScheme(): void
    {
        $this->assertSame('ws', Messaging::normalizeScheme('wss'));
        $this->assertSame('ws', Messaging::normalizeScheme('WebSocket'));
        $this->assertSame('mqtt', Messaging::normalizeScheme('mqtts'));
        $this->assertSame('sse', Messaging::normalizeScheme('eventsource'));
        $this->assertSame('udp', Messaging::normalizeScheme('dgram'));
    }

    public function testParseUrl(): void
    {
        $info = Messaging::parseUrl('wss://example.com:443/path?token=abc');
        $this->assertSame('ws', $info['scheme']);
        $this->assertSame('example.com', $info['host']);
        $this->assertSame(443, $info['port']);
        $this->assertTrue($info['tls']);
        $this->assertSame('abc', $info['query']['token'] ?? null);
    }

    public function testDefaultPort(): void
    {
        $this->assertSame(80, Messaging::defaultPort('ws'));
        $this->assertSame(443, Messaging::defaultPort('ws', true));
        $this->assertSame(1883, Messaging::defaultPort('mqtt'));
        $this->assertSame(8883, Messaging::defaultPort('mqtt', true));
    }
}
