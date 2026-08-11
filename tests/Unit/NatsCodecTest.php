<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Nats\NatsCodec;
use PHPUnit\Framework\TestCase;

final class NatsCodecTest extends TestCase
{
    public function test_encode_info(): void
    {
        $out = NatsCodec::encodeInfo(['server_id' => 'abc', 'max_payload' => 1024]);
        $this->assertStringStartsWith('INFO ', $out);
        $this->assertStringEndsWith("\r\n", $out);
        $json = json_decode(trim(substr($out, 5)), true);
        $this->assertSame('abc', $json['server_id']);
        $this->assertSame(1024, $json['max_payload']);
    }

    public function test_encode_connect(): void
    {
        $out = NatsCodec::encodeConnect(['name' => 'kode']);
        $this->assertStringStartsWith('CONNECT ', $out);
    }

    public function test_encode_pub(): void
    {
        $out = NatsCodec::encodePub('orders.created', 'hello');
        $this->assertSame("PUB orders.created 5\r\nhello\r\n", $out);
    }

    public function test_encode_pub_with_reply(): void
    {
        $out = NatsCodec::encodePub('orders.created', 'hi', '_INBOX.1');
        $this->assertSame("PUB orders.created _INBOX.1 2\r\nhi\r\n", $out);
    }

    public function test_encode_sub(): void
    {
        $out = NatsCodec::encodeSub('orders.*', 1);
        $this->assertSame("SUB orders.* 1\r\n", $out);
    }

    public function test_encode_sub_with_queue_group(): void
    {
        $out = NatsCodec::encodeSub('orders.*', 2, 'workers');
        $this->assertSame("SUB orders.* workers 2\r\n", $out);
    }

    public function test_encode_ping_pong(): void
    {
        $this->assertSame("PING\r\n", NatsCodec::encodePing());
        $this->assertSame("PONG\r\n", NatsCodec::encodePong());
    }

    public function test_parse_with_payload(): void
    {
        $frame = "PUB foo 5\r\nhello\r\n";
        $parsed = NatsCodec::parseWithPayload($frame);
        $this->assertNotNull($parsed);
        $this->assertSame('PUB', $parsed['command']['op']);
        $this->assertSame('foo', $parsed['command']['args'][0]);
        $this->assertSame('hello', $parsed['command']['payload']);
        $this->assertSame(strlen($frame), $parsed['parsed']);
    }

    public function test_parse_with_payload_incomplete(): void
    {
        $frame = "PUB foo 5\r\nhe";
        $this->assertNull(NatsCodec::parseWithPayload($frame));
    }

    public function test_encode_msg(): void
    {
        $out = NatsCodec::encodeMsg('orders.created', 7, 'hello', '_INBOX.99');
        $this->assertSame("MSG orders.created 7 _INBOX.99 5\r\nhello\r\n", $out);
    }
}
