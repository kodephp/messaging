<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Stomp\StompCodec;
use PHPUnit\Framework\TestCase;

final class StompCodecTest extends TestCase
{
    public function test_encode_decode_frame(): void
    {
        $frame = StompCodec::encodeFrame(
            StompCodec::COMMAND_SEND,
            ['destination' => '/queue/test', 'content-length' => '5'],
            'hello',
        );
        $this->assertStringEndsWith("\x00", $frame);

        $decoded = StompCodec::decodeFrame($frame);
        $this->assertNotNull($decoded);
        $this->assertSame('SEND', $decoded['command']);
        $this->assertSame('/queue/test', $decoded['headers']['destination']);
        $this->assertSame('5', $decoded['headers']['content-length']);
        $this->assertSame('hello', $decoded['body']);
        $this->assertSame(strlen($frame), $decoded['consumed']);
    }

    public function test_encode_connect(): void
    {
        $out = StompCodec::encodeConnect(['host' => 'broker', 'login' => 'u']);
        $this->assertStringStartsWith('CONNECT'.StompCodec::LF, $out);
        $this->assertStringContainsString('host:broker'.StompCodec::LF, $out);
        $this->assertStringContainsString('login:u'.StompCodec::LF, $out);
    }

    public function test_encode_stomp(): void
    {
        $out = StompCodec::encodeStomp([]);
        $this->assertStringStartsWith('STOMP'.StompCodec::LF, $out);
    }

    public function test_encode_subscribe(): void
    {
        $out = StompCodec::encodeSubscribe('/queue/a', 'sub-1');
        $decoded = StompCodec::decodeFrame($out);
        $this->assertNotNull($decoded);
        $this->assertSame('SUBSCRIBE', $decoded['command']);
        $this->assertSame('/queue/a', $decoded['headers']['destination']);
        $this->assertSame('sub-1', $decoded['headers']['id']);
        $this->assertSame('auto', $decoded['headers']['ack']);
    }

    public function test_encode_send(): void
    {
        $out = StompCodec::encodeSend('/queue/x', 'body');
        $decoded = StompCodec::decodeFrame($out);
        $this->assertNotNull($decoded);
        $this->assertSame('SEND', $decoded['command']);
        $this->assertSame('/queue/x', $decoded['headers']['destination']);
        $this->assertSame('4', $decoded['headers']['content-length']);
        $this->assertSame('body', $decoded['body']);
    }

    public function test_encode_connected(): void
    {
        $out = StompCodec::encodeConnected();
        $decoded = StompCodec::decodeFrame($out);
        $this->assertNotNull($decoded);
        $this->assertSame('CONNECTED', $decoded['command']);
        $this->assertSame('1.2', $decoded['headers']['version']);
    }

    public function test_encode_ack(): void
    {
        $out = StompCodec::encodeAck('msg-1');
        $decoded = StompCodec::decodeFrame($out);
        $this->assertNotNull($decoded);
        $this->assertSame('ACK', $decoded['command']);
        $this->assertSame('msg-1', $decoded['headers']['id']);
    }
}
