<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Grpc\GrpcCodec;
use PHPUnit\Framework\TestCase;

final class GrpcCodecTest extends TestCase
{
    public function test_encode(): void
    {
        $frame = GrpcCodec::encode('hello');
        $this->assertSame(10, strlen($frame));
        $this->assertSame("\x00", $frame[0]);
        $this->assertSame(pack('N', 5), substr($frame, 1, 4));
        $this->assertSame('hello', substr($frame, 5));
    }

    public function test_encode_compressed_flag(): void
    {
        $frame = GrpcCodec::encode('hi', true);
        $this->assertSame(GrpcCodec::COMPRESSED, ord($frame[0]));
    }

    public function test_decode(): void
    {
        $frame = GrpcCodec::encode('hello');
        $decoded = GrpcCodec::decode($frame);
        $this->assertNotNull($decoded);
        $this->assertFalse($decoded['compressed']);
        $this->assertSame('hello', $decoded['payload']);
        $this->assertSame(strlen($frame), $decoded['consumed']);
    }

    public function test_decode_incomplete(): void
    {
        $this->assertNull(GrpcCodec::decode("\x00\x00\x00\x00\x05he"));
    }

    public function test_encode_trailers(): void
    {
        $trailers = GrpcCodec::encodeTrailers(GrpcCodec::STATUS_OK);
        $this->assertStringContainsString('grpc-status: 0', $trailers);
    }

    public function test_encode_trailers_with_message(): void
    {
        $trailers = GrpcCodec::encodeTrailers(GrpcCodec::STATUS_INTERNAL, 'server error');
        $this->assertStringContainsString('grpc-status: 13', $trailers);
        $this->assertStringContainsString('grpc-message: server%20error', $trailers);
    }

    public function test_parse_status(): void
    {
        $parsed = GrpcCodec::parseStatus(['grpc-status' => '0', 'grpc-message' => '']);
        $this->assertSame(GrpcCodec::STATUS_OK, $parsed['status']);
    }
}
