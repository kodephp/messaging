<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebSocket;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Adapter\WebSocket\Codec\Frame;
use Kode\Messaging\Adapter\WebSocket\Codec\Handshake;
use Kode\Messaging\Adapter\WebSocket\Codec\OpCode;
use Kode\Messaging\Client\Builder as ClientBuilder;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\WebSocketException;
use Kode\Messaging\Message\Message;

/**
 * WebSocket 客户端适配器
 */
final class Client extends AbstractAdapter
{
    public static function scheme(): string
    {
        return 'ws';
    }

    public function version(): string
    {
        return 'rfc6455';
    }

    public function connect(array $config = []): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 80);
        $path = $config['path'] ?? '/';
        $tls  = (bool)($config['tls'] ?? false);

        $errno = 0;
        $errstr = '';
        $remote = ($tls ? 'tls' : 'tcp') . "://{$host}:{$port}";
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            throw new WebSocketException("无法连接 {$remote}: {$errstr}", 5001, [
                'host' => $host, 'port' => $port, 'errno' => $errno,
            ]);
        }

        $headers = $config['headers'] ?? [];
        $request = Handshake::clientRequest(
            "{$host}:{$port}",
            $path,
            $config['origin'] ?? null,
            $config['subprotocol'] ?? null,
            $headers,
        );
        @fwrite($socket, $request);

        $response = '';
        $deadline = microtime(true) + 10;
        while (strpos($response, "\r\n\r\n") === false && microtime(true) < $deadline) {
            $chunk = @fread($socket, 2048);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        if (strpos($response, "\r\n\r\n") === false) {
            @fclose($socket);
            throw new WebSocketException('握手超时', 5002);
        }

        // 验证响应（accept 用 key 计算）
        if (preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $request, $km) === 1) {
            $expected = Handshake::acceptKey($km[1]);
            try {
                Handshake::verifyServer($response, ['expected_accept' => $expected]);
            } catch (WebSocketException $e) {
                @fclose($socket);
                throw $e;
            }
        }

        return new ClientConnection(
            ClientConnection::generateId('ws'),
            'ws',
            stream_socket_get_name($socket, true) ?: "{$host}:{$port}",
            $socket,
            $this->logger,
        );
    }

    public function listen(string $host, int $port): void
    {
        throw new \LogicException('Client 适配器不支持 listen()');
    }

    public function run(): void
    {
        // 客户端适配器由 ClientBuilder 显式调用 connect()
    }

    public static function autoRegister(): void
    {
        Registry::register('ws', self::class);
    }
}
