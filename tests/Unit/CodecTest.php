<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Mqtt\Packet\Codec as MqttCodec;
use Kode\Messaging\Adapter\WebSocket\Codec\Frame;
use Kode\Messaging\Adapter\WebSocket\Codec\OpCode;
use PHPUnit\Framework\TestCase;

final class CodecTest extends TestCase
{
    public function test_web_socket_text_frame_encode(): void
    {
        $frame = new Frame(true, OpCode::TEXT, 'hello');
        $bytes = $frame->encode();
        // 0x81 (FIN + TEXT) + 0x05 (length) + "hello"
        $this->assertSame("\x81\x05hello", $bytes);
    }

    public function test_web_socket_frame_decode(): void
    {
        $original = new Frame(true, OpCode::TEXT, 'hello');
        $bytes = $original->encode(masked: true);
        $maskOffset = 2;
        $mask = substr($bytes, $maskOffset, 4);
        $maskedPayload = substr($bytes, $maskOffset + 4);
        $unmasked = $maskedPayload ^ str_repeat($mask, 1);
        $unmasked = substr($unmasked, 0, 5);
        $rebuilt = chr(0x81).chr(0x80 | 5).$mask.$maskedPayload;
        $decoded = Frame::decode($rebuilt);
        $this->assertSame(OpCode::TEXT, $decoded->opcode);
        $this->assertSame('hello', $decoded->payload);
        $this->assertTrue($decoded->fin);
    }

    public function test_web_socket_frame_ping(): void
    {
        // 服务端发往客户端的 PING 帧是 unmasked
        $frame = Frame::ping('ping-data');
        $bytes = $frame->encode();
        $decoded = Frame::decode($bytes, mustMask: false);
        $this->assertSame(OpCode::PING, $decoded->opcode);
        $this->assertSame('ping-data', $decoded->payload);
    }

    public function test_mqtt_encode_remaining_length(): void
    {
        $this->assertSame("\x00", MqttCodec::encodeRemainingLength(0));
        $this->assertSame("\x7F", MqttCodec::encodeRemainingLength(127));
        $this->assertSame("\x80\x01", MqttCodec::encodeRemainingLength(128));
        $this->assertSame("\xFF\x7F", MqttCodec::encodeRemainingLength(16383));
    }

    public function test_mqtt_decode_remaining_length(): void
    {
        $data = "\x80\x01".'abc';
        $offset = 0;
        $this->assertSame(128, MqttCodec::decodeRemainingLength($data, $offset));
        $this->assertSame(2, $offset);
    }

    public function test_mqtt_encode_string(): void
    {
        $this->assertSame("\x00\x05hello", MqttCodec::encodeString('hello'));
    }
}
