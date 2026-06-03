<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Udp;

use Kode\Messaging\Connection\Connection;
use Kode\Messaging\Exception\UdpException;

/**
 * UDP 连接（发送方抽象）。
 *
 * 注意：UDP 是无连接的，"连接"实际指向一个对端地址 (ip:port)。
 * 同一连接发送的所有 datagram 都会到达该对端。
 */
class UdpConnection extends Connection
{
    /**
     * @param resource $socket 底层 socket
     */
    public function __construct(
        string $connId,
        string $protocol,
        string $remoteAddress,
        protected $socket,
        protected string $peer,
    ) {
        parent::__construct($connId, $protocol, $remoteAddress);
    }

    public function send(mixed $payload, array $options = []): bool
    {
        if (!$this->open) {
            return false;
        }
        $data = is_string($payload) ? $payload : (string)json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $max = (int)($options['max_packet_size'] ?? 65_507);
        if (strlen($data) > $max) {
            throw UdpException::packetTooBig(strlen($data), $max);
        }
        $bytes = @stream_socket_sendto($this->socket, $data, 0, $this->peer);
        if ($bytes === false) {
            $this->close(0, 'send failed');
            return false;
        }
        return true;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->open = false;
        foreach ($this->closeCallbacks as $cb) {
            try {
                $cb(null);
            } catch (\Throwable) {
            }
        }
        $this->closeCallbacks = [];
    }

    /**
     * 切换目标对端。
     */
    public function setPeer(string $ip, int $port): void
    {
        $this->peer = "{$ip}:{$port}";
        $this->remoteAddress = $this->peer;
    }

    public function peer(): string
    {
        return $this->peer;
    }
}
