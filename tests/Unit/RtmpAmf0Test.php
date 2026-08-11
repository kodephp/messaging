<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Rtmp\Amf0;
use Kode\Messaging\Adapter\Rtmp\RtmpChunk;
use PHPUnit\Framework\TestCase;

final class RtmpAmf0Test extends TestCase
{
    public function test_encode_decode_number(): void
    {
        $encoded = Amf0::encode(3.14);
        $offset = 0;
        $decoded = Amf0::decode($encoded, $offset);
        $this->assertIsFloat($decoded);
        $this->assertEqualsWithDelta(3.14, $decoded, 0.0001);
    }

    public function test_encode_decode_boolean(): void
    {
        $encoded = Amf0::encode(true);
        $offset = 0;
        $this->assertTrue(Amf0::decode($encoded, $offset));
    }

    public function test_encode_decode_string(): void
    {
        $encoded = Amf0::encode('hello');
        $offset = 0;
        $this->assertSame('hello', Amf0::decode($encoded, $offset));
    }

    public function test_encode_decode_null(): void
    {
        $encoded = Amf0::encode(null);
        $offset = 0;
        $this->assertNull(Amf0::decode($encoded, $offset));
    }

    public function test_encode_decode_object(): void
    {
        $value = ['app' => 'live', 'flashVer' => 'FMLE/3.0'];
        $encoded = Amf0::encode($value);
        $offset = 0;
        $decoded = Amf0::decode($encoded, $offset);
        $this->assertSame('live', $decoded['app']);
        $this->assertSame('FMLE/3.0', $decoded['flashVer']);
    }

    public function test_encode_decode_array(): void
    {
        $value = [1, 2, 3];
        $encoded = Amf0::encode($value);
        $offset = 0;
        $decoded = Amf0::decode($encoded, $offset);
        $this->assertCount(3, $decoded);
        $this->assertEqualsWithDelta(1, $decoded[0], 0.0001);
        $this->assertEqualsWithDelta(2, $decoded[1], 0.0001);
        $this->assertEqualsWithDelta(3, $decoded[2], 0.0001);
    }

    public function test_encode_basic_header_small_csid(): void
    {
        $header = RtmpChunk::encodeBasicHeader(RtmpChunk::FMT_FULL, 4);
        $this->assertSame(1, strlen($header));
        $b = ord($header[0]);
        $this->assertSame(0, $b >> 6);
        $this->assertSame(4, $b & 0x3F);
    }

    public function test_encode_basic_header_large_csid(): void
    {
        $header = RtmpChunk::encodeBasicHeader(RtmpChunk::FMT_FULL, 200);
        $this->assertSame(2, strlen($header));
        $this->assertSame(0, ord($header[0]) & 0x3F);
        $this->assertSame(200 - 64, ord($header[1]));
    }

    public function test_decode_basic_header(): void
    {
        $encoded = RtmpChunk::encodeBasicHeader(RtmpChunk::FMT_SAME_STREAM, 3);
        $decoded = RtmpChunk::decodeBasicHeader($encoded);
        $this->assertNotNull($decoded);
        $this->assertSame(RtmpChunk::FMT_SAME_STREAM, $decoded['fmt']);
        $this->assertSame(3, $decoded['csid']);
    }

    public function test_encode_decode_message_header_full(): void
    {
        $header = RtmpChunk::encodeMessageHeader(
            RtmpChunk::FMT_FULL,
            1234,
            100,
            0x14,
            1,
        );
        $this->assertSame(11, strlen($header));
        $decoded = RtmpChunk::decodeMessageHeader(RtmpChunk::FMT_FULL, $header);
        $this->assertNotNull($decoded);
        $this->assertSame(1234, $decoded['timestamp']);
        $this->assertSame(100, $decoded['messageLength']);
        $this->assertSame(0x14, $decoded['messageType']);
    }

    public function test_encode_decode_message_header_extended_timestamp(): void
    {
        $header = RtmpChunk::encodeMessageHeader(
            RtmpChunk::FMT_FULL,
            0xFFFFFF, // 需要扩展时间戳
            50,
            0x08,
            0,
        );
        $this->assertSame(15, strlen($header));
        $decoded = RtmpChunk::decodeMessageHeader(RtmpChunk::FMT_FULL, $header);
        $this->assertNotNull($decoded);
        $this->assertSame(0xFFFFFF, $decoded['timestamp']);
        $this->assertSame(50, $decoded['messageLength']);
    }

    public function test_build_handshake_response(): void
    {
        $c1 = random_bytes(1536);
        $resp = RtmpChunk::buildHandshakeResponse($c1);
        $this->assertSame(1 + 1536 + 1536, strlen($resp));
        $this->assertSame("\x03", $resp[0]);
        $s2 = substr($resp, 1 + 1536, 1536);
        $this->assertSame($c1, $s2);
    }
}
