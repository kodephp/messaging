<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\WebTransport\WebTransportCodec;
use PHPUnit\Framework\TestCase;

final class WebTransportCodecTest extends TestCase
{
    public function testEncodeConnectRequest(): void
    {
        $req = WebTransportCodec::encodeConnectRequest('/wt/test', ['host' => 'example.com']);
        $this->assertStringStartsWith("CONNECT /wt/test HTTP/1.1\r\n", $req);
        $this->assertStringContainsString('Host: example.com', $req);
        $this->assertStringContainsString(WebTransportCodec::ENABLE_WT_OVER_H2 . ': 1', $req);
    }

    public function testDecodeConnectResponse(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nFoo: Bar\r\n\r\n";
        $resp = WebTransportCodec::decodeConnectResponse($raw);
        $this->assertNotNull($resp);
        $this->assertSame(200, $resp['status']);
        $this->assertSame('OK', $resp['reason']);
        $this->assertSame('Bar', $resp['headers']['foo']);
    }

    public function testEncodeDatagram(): void
    {
        $dgram = WebTransportCodec::encodeDatagram('payload');
        $this->assertSame("\x00payload", $dgram);

        $dgram = WebTransportCodec::encodeDatagram('payload', true);
        $this->assertSame("\x01payload", $dgram);
    }

    public function testDecodeDatagram(): void
    {
        $decoded = WebTransportCodec::decodeDatagram("\x00hello");
        $this->assertNotNull($decoded);
        $this->assertFalse($decoded['reliable']);
        $this->assertSame('hello', $decoded['payload']);

        $decoded = WebTransportCodec::decodeDatagram("\x01world");
        $this->assertNotNull($decoded);
        $this->assertTrue($decoded['reliable']);
        $this->assertSame('world', $decoded['payload']);
    }

    public function testDecodeEmptyDatagram(): void
    {
        $this->assertNull(WebTransportCodec::decodeDatagram(''));
    }
}
