<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Coap;

use Kode\Messaging\Exception\CoapException;

/**
 * CoAP 数据包编解码（RFC 7252 §3）
 *
 * 包格式：
 *
 *  0                   1                   2                   3
 *  0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |Ver| T |  TKL  |      Code     |          Message ID           |
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |   Token (if any, TKL bytes) ...
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |   Options (if any) ...
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |1 1 1 1 1 1 1 1|    Payload (if any) ...
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 */
final class CoapPacket
{
    public const VERSION = 1;
    public const PAYLOAD_MARKER = 0xFF;

    public function __construct(
        public int $type,
        public int $tokenLength,
        public float $code,
        public int $messageId,
        public string $token = '',
        /** @var list<array{number:int, value:string}> */
        public array $options = [],
        public string $payload = '',
    ) {
    }

    /**
     * 编码为字节串。
     */
    public function encode(): string
    {
        if ($this->tokenLength !== \strlen($this->token)) {
            throw CoapException::packetEncodeFailed(
                'token length mismatch',
                ['expected' => $this->tokenLength, 'actual' => \strlen($this->token)],
            );
        }

        $byte0 = (self::VERSION << 6) | (($this->type & 0x03) << 4) | ($this->tokenLength & 0x0F);
        $byte1 = CoapCode::encode($this->code);
        $byte23 = pack('n', $this->messageId & 0xFFFF);

        $buf = chr($byte0) . chr($byte1) . $byte23;
        if ($this->token !== '') {
            $buf .= $this->token;
        }
        $buf .= $this->encodeOptions();
        if ($this->payload !== '') {
            $buf .= chr(self::PAYLOAD_MARKER) . $this->payload;
        }
        return $buf;
    }

    /**
     * 从字节串解码。
     */
    public static function decode(string $data): self
    {
        if (\strlen($data) < 4) {
            throw CoapException::packetParseFailed(
                'packet too short',
                ['length' => \strlen($data)],
            );
        }

        $byte0 = ord($data[0]);
        $ver = ($byte0 >> 6) & 0x03;
        $type = ($byte0 >> 4) & 0x03;
        $tkl = $byte0 & 0x0F;
        $code = CoapCode::decode(ord($data[1]));
        $mid = unpack('n', substr($data, 2, 2))[1];

        $offset = 4;
        if ($tkl > 0) {
            if (\strlen($data) < $offset + $tkl) {
                throw CoapException::packetParseFailed('token truncated', ['tkl' => $tkl]);
            }
            $token = substr($data, $offset, $tkl);
            $offset += $tkl;
        } else {
            $token = '';
        }

        [$options, $offset] = self::decodeOptions($data, $offset);

        $payload = '';
        if ($offset < \strlen($data) && ord($data[$offset]) === self::PAYLOAD_MARKER) {
            $offset++;
            $payload = substr($data, $offset);
        }

        if ($ver !== self::VERSION) {
            throw CoapException::packetParseFailed(
                'unsupported version',
                ['version' => $ver],
            );
        }

        return new self($type, $tkl, $code, $mid, $token, $options, $payload);
    }

    /**
     * 编码选项（按 option number 升序）。
     *
     * 编码格式：delta:length:extended
     *  - delta 0..12: 直接编码
     *  - delta 13:    后跟 1 字节扩展
     *  - delta 14:    后跟 2 字节扩展
     *  - delta 15:    保留（payload marker）
     */
    private function encodeOptions(): string
    {
        $buf = '';
        $prev = 0;
        foreach ($this->options as $opt) {
            $num = (int)$opt['number'];
            $val = (string)$opt['value'];
            $delta = $num - $prev;
            $length = \strlen($val);

            $deltaExt = 0;
            $deltaBytes = '';
            if ($delta < 13) {
                $deltaNibble = $delta;
            } elseif ($delta < 269) {
                $deltaNibble = 13;
                $deltaBytes = chr($delta - 13);
            } elseif ($delta < 65536) {
                $deltaNibble = 14;
                $deltaBytes = pack('n', $delta - 269);
            } else {
                throw CoapException::packetEncodeFailed(
                    'option delta too large',
                    ['delta' => $delta, 'number' => $num],
                );
            }
            $lengthExt = 0;
            $lengthBytes = '';
            if ($length < 13) {
                $lengthNibble = $length;
            } elseif ($length < 269) {
                $lengthNibble = 13;
                $lengthBytes = chr($length - 13);
            } elseif ($length < 65536) {
                $lengthNibble = 14;
                $lengthBytes = pack('n', $length - 269);
            } else {
                throw CoapException::packetEncodeFailed(
                    'option length too large',
                    ['length' => $length, 'number' => $num],
                );
            }

            $buf .= chr(($deltaNibble << 4) | $lengthNibble) . $deltaBytes . $lengthBytes . $val;
            $prev = $num;
        }
        return $buf;
    }

    /**
     * 解码选项段。
     *
     * @return array{0: list<array{number:int, value:string}>, 1: int} 选项列表与下一偏移
     */
    private static function decodeOptions(string $data, int $offset): array
    {
        $options = [];
        $prev = 0;
        $len = \strlen($data);
        while ($offset < $len) {
            $b = ord($data[$offset]);
            if ($b === self::PAYLOAD_MARKER) {
                break;
            }
            $deltaNibble = ($b >> 4) & 0x0F;
            $lengthNibble = $b & 0x0F;
            $offset++;

            $delta = match ($deltaNibble) {
                0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 => $deltaNibble,
                13 => ($offset < $len ? ord($data[$offset++]) : 0) + 13,
                14 => ($offset + 1 < $len ? unpack('n', substr($data, $offset, 2))[1] : 0) + 269,
                default => throw CoapException::packetParseFailed(
                    'invalid option delta nibble 15',
                    ['offset' => $offset - 1],
                ),
            };
            if ($deltaNibble === 14) {
                $offset += 2;
            }

            $length = match ($lengthNibble) {
                0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 => $lengthNibble,
                13 => ($offset < $len ? ord($data[$offset++]) : 0) + 13,
                14 => ($offset + 1 < $len ? unpack('n', substr($data, $offset, 2))[1] : 0) + 269,
                default => throw CoapException::packetParseFailed(
                    'invalid option length nibble 15',
                    ['offset' => $offset - 1],
                ),
            };
            if ($lengthNibble === 14) {
                $offset += 2;
            }

            if ($offset + $length > $len) {
                throw CoapException::packetParseFailed(
                    'option value out of bounds',
                    ['offset' => $offset, 'length' => $length, 'total' => $len],
                );
            }
            $value = substr($data, $offset, $length);
            $offset += $length;

            $prev += $delta;
            $options[] = ['number' => $prev, 'value' => $value];
        }
        return [$options, $offset];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'      => $this->type,
            'type_name' => CoapType::name($this->type),
            'code'      => $this->code,
            'code_name' => CoapCode::name($this->code),
            'mid'       => $this->messageId,
            'token'     => bin2hex($this->token),
            'options'   => array_map(
                fn($o) => [
                    'number' => $o['number'],
                    'name'   => CoapOption::name($o['number']),
                    'value'  => $o['value'],
                ],
                $this->options,
            ),
            'payload'   => $this->payload,
        ];
    }
}
