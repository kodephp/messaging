<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Coap;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\CoapException;
use LogicException;

/**
 * CoAP 客户端
 *
 * 用法：
 *   $conn = Messaging::client('coap://server.local:5683')->connect();
 *   $conn->get('/sensors/temp', function ($mid, $response) {
 *       echo "Response: " . $response->payload() . "\n";
 *   });
 */
final class Client extends AbstractAdapter
{
    private ?CoapConnection $conn = null;

    /** @var null|resource */
    private $socket = null;

    private ?string $peer = null;

    public static function scheme(): string
    {
        return 'coap';
    }

    public function version(): string
    {
        return 'rfc7252';
    }

    public function connect(array $config): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 5683);
        $this->peer = "{$host}:{$port}";

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
            throw CoapException::bindFailed($host, $port, (string) $errstr);
        }
        stream_set_timeout($this->socket, 1);

        $this->conn = new CoapConnection(
            CoapConnection::generateId('coap'),
            'coap',
            $this->peer,
            $this->socket,
            $this->peer,
            reliable: $config['reliable'] ?? true,
        );

        return $this->conn;
    }

    public function listen(string $host, int $port): void
    {
        throw new LogicException('CoAP Client 不支持 listen()');
    }

    public function run(): void
    {
        // 客户端的循环逻辑由业务直接驱动 sendRequest / 读响应
    }

    public function shutdown(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->conn = null;
    }

    public static function autoRegister(): void
    {
        Registry::register('coap', self::class);
    }
}
