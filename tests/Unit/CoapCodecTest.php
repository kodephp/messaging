<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Coap\CoapCode;
use Kode\Messaging\Adapter\Coap\CoapOption;
use Kode\Messaging\Adapter\Coap\CoapPacket;
use Kode\Messaging\Adapter\Coap\CoapType;
use PHPUnit\Framework\TestCase;

final class CoapCodecTest extends TestCase
{
    public function testCodeEncodeDecode(): void
    {
        $this->assertSame(0x01, CoapCode::encode(CoapCode::GET));
        $this->assertSame(0x02, CoapCode::encode(CoapCode::POST));
        $this->assertSame(0x45, CoapCode::encode(CoapCode::CONTENT));      // 2.05
        $this->assertSame(0x84, CoapCode::encode(CoapCode::NOT_FOUND));   // 4.04
        $this->assertSame(0xA0, CoapCode::encode(CoapCode::INTERNAL_SERVER_ERROR)); // 5.00
        $this->assertSame(CoapCode::CONTENT, CoapCode::decode(0x45));
        $this->assertSame(CoapCode::NOT_FOUND, CoapCode::decode(0x84));
        $this->assertSame(CoapCode::INTERNAL_SERVER_ERROR, CoapCode::decode(0xA0));
    }

    public function testTypeName(): void
    {
        $this->assertSame('CON', CoapType::name(CoapType::CON));
        $this->assertSame('NON', CoapType::name(CoapType::NON));
        $this->assertSame('ACK', CoapType::name(CoapType::ACK));
        $this->assertSame('RST', CoapType::name(CoapType::RST));
    }

    public function testSimplePacketEncode(): void
    {
        $packet = new CoapPacket(
            type: CoapType::CON,
            tokenLength: 0,
            code: CoapCode::GET,
            messageId: 0x1234,
            token: '',
            options: [
                ['number' => CoapOption::URI_PATH, 'value' => 'sensors'],
                ['number' => CoapOption::URI_PATH, 'value' => 'temp'],
            ],
            payload: '',
        );

        $bytes = $packet->encode();
        // Ver=1, T=CON(0), TKL=0 → 0x40
        // Code=GET(0.01) → 0x01
        // MID=0x1234 → 0x12 0x34
        // Options: Uri-Path "sensors" (delta 11, len 7) → 0xB7, "sensors"
        //          Uri-Path "temp" (delta 0, len 4) → 0x04, "temp"
        $this->assertSame("\x40\x01\x12\x34" . "\xB7" . "sensors" . "\x04" . "temp", $bytes);
    }

    public function testPacketDecode(): void
    {
        $bytes = "\x40\x01\x12\x34" . "\xB7" . "sensors" . "\x04" . "temp";
        $packet = CoapPacket::decode($bytes);

        $this->assertSame(CoapType::CON, $packet->type);
        $this->assertSame(0, $packet->tokenLength);
        $this->assertSame(CoapCode::GET, $packet->code);
        $this->assertSame(0x1234, $packet->messageId);
        $this->assertCount(2, $packet->options);
        $this->assertSame(CoapOption::URI_PATH, $packet->options[0]['number']);
        $this->assertSame('sensors', $packet->options[0]['value']);
        $this->assertSame('temp', $packet->options[1]['value']);
    }

    public function testPacketWithToken(): void
    {
        $packet = new CoapPacket(
            type: CoapType::ACK,
            tokenLength: 2,
            code: CoapCode::CONTENT,
            messageId: 0xABCD,
            token: "\x01\x02",
            options: [
                ['number' => CoapOption::CONTENT_FORMAT, 'value' => chr(CoapOption::FMT_JSON)],
            ],
            payload: '{"v":1}',
        );

        $bytes = $packet->encode();
        $this->assertSame(0x42, ord($bytes[0]) & 0x4F); // ver=1, t=ACK, tkl=2
        $this->assertSame(0x45, ord($bytes[1]));         // code=2.05
        $this->assertSame("\xAB\xCD", substr($bytes, 2, 2));
        $this->assertSame("\x01\x02", substr($bytes, 4, 2));
        // payload marker
        $this->assertSame(0xFF, ord($bytes[strlen($bytes) - strlen('{"v":1}') - 1]));

        $decoded = CoapPacket::decode($bytes);
        $this->assertSame("\x01\x02", $decoded->token);
        $this->assertSame('{"v":1}', $decoded->payload);
    }

    public function testLargeOptionDelta(): void
    {
        // delta 269 → 14 + 0x0000
        $packet = new CoapPacket(
            type: CoapType::NON,
            tokenLength: 0,
            code: CoapCode::GET,
            messageId: 0x0001,
            options: [
                ['number' => 11, 'value' => 'a'],   // delta 11
                ['number' => 280, 'value' => 'b'],  // delta 269
            ],
            payload: '',
        );
        $bytes = $packet->encode();
        $decoded = CoapPacket::decode($bytes);
        $this->assertSame('a', $decoded->options[0]['value']);
        $this->assertSame('b', $decoded->options[1]['value']);
        $this->assertSame(280, $decoded->options[1]['number']);
    }

    public function testPacketTooShortThrows(): void
    {
        $this->expectException(\Kode\Messaging\Exception\CoapException::class);
        CoapPacket::decode("\x40\x01");
    }
}
