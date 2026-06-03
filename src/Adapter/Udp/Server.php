<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Udp;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Connection\Connection;
use Kode\Messaging\Exception\UdpException;
use Kode\Messaging\Message\Message as Msg;
use Kode\Messaging\Server\Builder as ServerBuilder;

/**
 * UDP 服务端（Datagram 抽象）
 *
 * 每个接收到的 datagram 视为一个 Message；连接是"逻辑连接"——
 * 它代表发送方地址 (ip:port)，而不是物理 socket。
 *
 * 适合：
 *  - 实时音视频（VoIP）
 *  - 实时游戏
 *  - DNS 响应
 *  - 简易 PUB/SUB
 */
final class Server extends AbstractAdapter
{
    /** @var resource|null */
    private $socket = null;

    /** @var array<string, UdpConnection> 按 ip:port 缓存的逻辑连接 */
    private array $connections = [];

    private ?ServerBuilder $builder = null;

    public static function scheme(): string
    {
        return 'udp';
    }

    public function version(): string
    {
        return 'rfc768';
    }

    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    protected function defaultConfig(): array
    {
        return [
            'max_packet_size'  => 65_507,
            'enable_broadcast' => true,
            'enable_multicast' => true,
            'socket_timeout'   => 30,
        ];
    }

    public function listen(string $host, int $port): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_server(
            "udp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND,
        );
        if ($this->socket === false) {
            throw UdpException::bindFailed($host, $port, $errstr);
        }

        $timeout = (int)($this->config['socket_timeout'] ?? 30);
        if ($timeout > 0) {
            stream_set_timeout($this->socket, $timeout);
        }

        if (($this->config['enable_broadcast'] ?? true)) {
            // 启用广播（SOL_SOCKET/SO_BROADCAST 是 POSIX 常量，全局可见）
            @socket_set_option($this->toSocket($this->socket), SOL_SOCKET, SO_BROADCAST, 1);
        }

        $this->logger->info("UDP listening on {$host}:{$port}");
    }

    /**
     * 将 stream resource 转为 ext-sockets 的 socket 资源（可选）。
     */
    private function toSocket($stream): \Socket|false
    {
        if (extension_loaded('sockets')) {
            return @socket_import_stream($stream);
        }
        return false;
    }

    public function run(): void
    {
        $this->running = true;
        while ($this->running) {
            $buf = @stream_socket_recvfrom($this->socket, (int)($this->config['max_packet_size'] ?? 65_507), 0, $peer);
            if ($buf === false || $buf === '') {
                usleep(1_000);
                continue;
            }
            $this->handleDatagram($buf, (string)$peer);
        }
    }

    private function handleDatagram(string $data, string $peer): void
    {
        if (!isset($this->connections[$peer])) {
            $this->connections[$peer] = new UdpConnection(
                Connection::generateId('udp'),
                'udp',
                $peer,
                $this->socket,
                $peer,
            );
            $this->builder?->emit('connection.open', ['connection' => $this->connections[$peer]]);
        }

        $conn = $this->connections[$peer];

        $msg = Msg::fromRaw(
            $data,
            'udp',
            topic: null,
            context: [
                'connection_id'  => $conn->id(),
                'remote_address' => $peer,
            ],
        );
        $this->builder?->emit('message.received', ['connection' => $conn, 'message' => $msg]);
    }

    public function connect(array $config = []): \Kode\Messaging\Contract\ConnectionInterface
    {
        throw new \LogicException('UDP Server 不支持 connect()');
    }

    public function shutdown(): void
    {
        $this->running = false;
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('udp', self::class);
    }
}
