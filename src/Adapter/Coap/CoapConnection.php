<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Coap;

use Kode\Messaging\Connection\Connection;

use function strlen;

use Throwable;

/**
 * CoAP 连接
 *
 * 与 UDP 类似，"连接"指代对端地址 (ip:port)。
 * 一次 CoAP 交互是 CON/ACK 或 NON 单包；不是真正的流式连接。
 */
class CoapConnection extends Connection
{
    /**
     * @param resource $socket
     */
    public function __construct(
        string $connId,
        string $protocol,
        string $remoteAddress,
        protected $socket,
        protected string $peer,
        protected bool $reliable = true,
    ) {
        parent::__construct($connId, $protocol, $remoteAddress);
    }

    /**
     * 发送 CoAP 请求（CON/NON），返回 packet_id。
     */
    public function sendRequest(float $code, string $path, string $payload = '', array $options = []): int
    {
        $mid = $options['mid'] ?? random_int(0, 0xFFFF);
        $type = $options['type'] ?? ($this->reliable ? CoapType::CON : CoapType::NON);
        $token = $options['token'] ?? '';
        $accept = $options['accept'] ?? CoapOption::FMT_JSON;
        $contentFormat = $options['content_format'] ?? CoapOption::FMT_TEXT;

        $optList = [];
        // URI-Path 拆为多段
        foreach (array_filter(explode('/', $path), static fn(string $s) => $s !== '') as $seg) {
            $optList[] = ['number' => CoapOption::URI_PATH, 'value' => $seg];
        }
        if ($payload !== '') {
            $optList[] = ['number' => CoapOption::CONTENT_FORMAT, 'value' => $this->uintOption($contentFormat)];
        }
        $optList[] = ['number' => CoapOption::ACCEPT, 'value' => $this->uintOption((int) $accept)];
        foreach ($options['extra'] ?? [] as $k => $v) {
            $optList[] = ['number' => (int) $k, 'value' => (string) $v];
        }
        // 按 number 升序
        usort($optList, fn($a, $b) => $a['number'] <=> $b['number']);

        $packet = new CoapPacket(
            type: $type,
            tokenLength: strlen($token),
            code: $code,
            messageId: $mid,
            token: $token,
            options: $optList,
            payload: $payload,
        );

        return $this->sendPacket($packet);
    }

    /**
     * 发送已构造的 CoAP 数据包。
     */
    public function sendPacket(CoapPacket $packet): int
    {
        if (! $this->open) {
            return 0;
        }
        $data = $packet->encode();
        $bytes = @stream_socket_sendto($this->socket, $data, 0, $this->peer);
        if ($bytes === false) {
            $this->close(0, 'send failed');

            return 0;
        }
        $this->attrs['__last_mid'] = $packet->messageId;

        return $packet->messageId;
    }

    public function send(mixed $payload, array $options = []): bool
    {
        if (! $this->open) {
            return false;
        }
        // send() 默认是 CON GET（向后兼容）
        $code = (float) ($options['code'] ?? CoapCode::GET);
        $path = (string) ($options['path'] ?? '/');
        $body = is_string($payload) ? $payload : (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $this->sendRequest($code, $path, $body, $options) > 0;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->open = false;
        foreach ($this->closeCallbacks as $cb) {
            try {
                $cb(null);
            } catch (Throwable) {
            }
        }
        $this->closeCallbacks = [];
    }

    /**
     * 把整数编码为 CoAP option value（uint）。
     */
    private function uintOption(int $value): string
    {
        if ($value < 256) {
            return chr($value);
        }
        if ($value < 65536) {
            return pack('n', $value);
        }

        return substr(pack('N', $value), -4);
    }

    public function peer(): string
    {
        return $this->peer;
    }

    public function socket()
    {
        return $this->socket;
    }
}
