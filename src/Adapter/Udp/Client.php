<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Udp;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\UdpException;

/**
 * UDP 客户端
 *
 * 用法：
 *   $client = Messaging::client('udp://host:port')->connect();
 *   $client->send('hello');
 */
final class Client extends AbstractAdapter
{
    /** @var resource|null */
    private $socket = null;

    private ?UdpConnection $conn = null;

    public static function scheme(): string
    {
        return 'udp';
    }

    public function version(): string
    {
        return 'rfc768';
    }

    public function connect(array $config = []): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 8082);
        $peer = "{$host}:{$port}";

        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            "udp://{$host}:{$port}",
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT,
        );
        if ($this->socket === false) {
            throw UdpException::bindFailed($host, $port, $errstr);
        }

        $this->conn = new UdpConnection(
            UdpConnection::generateId('udp'),
            'udp',
            $peer,
            $this->socket,
            $peer,
        );
        return $this->conn;
    }

    public function listen(string $host, int $port): void
    {
        throw new \LogicException('UDP Client 不支持 listen()');
    }

    public function run(): void
    {
        // UDP 无连接，循环接收
        while ($this->running) {
            $buf = @stream_socket_recvfrom($this->socket, 65_507, 0, $peer);
            if ($buf === false || $buf === '') {
                usleep(1_000);
                continue;
            }
            $msg = \Kode\Messaging\Message\Message::fromRaw(
                $buf,
                'udp',
                topic: null,
                context: [
                    'connection_id'  => $this->conn?->id(),
                    'remote_address' => $peer,
                ],
            );
            $this->emit('message.received', ['connection' => $this->conn, 'message' => $msg]);
        }
    }

    public function shutdown(): void
    {
        $this->running = false;
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function emit(string $event, array $payload): void
    {
        // 由 ClientBuilder 监听
    }

    public static function autoRegister(): void
    {
        Registry::register('udp', self::class);
    }
}
