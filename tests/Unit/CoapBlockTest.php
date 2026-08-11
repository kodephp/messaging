<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Coap\CoapBlock;
use PHPUnit\Framework\TestCase;

final class CoapBlockTest extends TestCase
{
    public function test_block1_byte(): void
    {
        $bytes = CoapBlock::encode(5, true, 3);
        $this->assertSame(1, strlen($bytes));
        $decoded = CoapBlock::decode($bytes);
        $this->assertSame(5, $decoded['num']);
        $this->assertTrue($decoded['more']);
        $this->assertSame(3, $decoded['szx']);
        $this->assertSame(128, $decoded['size']);
    }

    public function test_block2_bytes(): void
    {
        $bytes = CoapBlock::encode(100, false, 4);
        $this->assertSame(2, strlen($bytes));
        $decoded = CoapBlock::decode($bytes);
        $this->assertSame(100, $decoded['num']);
        $this->assertFalse($decoded['more']);
        $this->assertSame(4, $decoded['szx']);
        $this->assertSame(256, $decoded['size']);
    }

    public function test_block3_bytes(): void
    {
        $bytes = CoapBlock::encode(65535, true, 6);
        $this->assertSame(3, strlen($bytes));
        $decoded = CoapBlock::decode($bytes);
        $this->assertSame(65535, $decoded['num']);
        $this->assertTrue($decoded['more']);
        $this->assertSame(6, $decoded['szx']);
        $this->assertSame(1024, $decoded['size']);
    }

    public function test_invalid_szx(): void
    {
        $this->expectException(\Kode\Messaging\Exception\CoapException::class);
        CoapBlock::encode(0, false, 7);
    }

    public function test_split(): void
    {
        $payload = str_repeat('A', 300);
        $blocks = CoapBlock::split($payload, 2);  // 64 bytes per block
        $this->assertCount(5, $blocks);
        $this->assertSame(64, strlen($blocks[0]['data']));
        $this->assertSame(64, strlen($blocks[1]['data']));
        $this->assertSame(64, strlen($blocks[2]['data']));
        $this->assertSame(64, strlen($blocks[3]['data']));
        $this->assertSame(44, strlen($blocks[4]['data']));
        $this->assertTrue($blocks[0]['more']);
        $this->assertTrue($blocks[3]['more']);
        $this->assertFalse($blocks[4]['more']);

        // reassemble
        $reassembled = '';
        foreach ($blocks as $b) {
            $reassembled .= $b['data'];
        }
        $this->assertSame($payload, $reassembled);
    }
}
