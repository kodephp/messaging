<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Sse;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\SseException;
use Kode\Messaging\Message\Message as Msg;

/**
 * SSE 客户端
 *
 * 用 stream_socket_client + fread 解析 event-stream 文本。
 */
final class Client extends AbstractAdapter
{
    /** @var resource|null */
    private $stream = null;

    public static function scheme(): string
    {
        return 'sse';
    }

    public function version(): string
    {
        return 'html5';
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
        $this->stream = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT,
        );
        if ($this->stream === false) {
            throw SseException::invalidEvent("无法连接 {$remote}: {$errstr}", ['host' => $host, 'port' => $port]);
        }
        $headers = $config['headers'] ?? [];
        $req = "GET {$path} HTTP/1.1\r\n";
        $req .= "Host: {$host}:{$port}\r\n";
        $req .= "Accept: text/event-stream\r\n";
        $req .= "Cache-Control: no-cache\r\n";
        $req .= "Connection: keep-alive\r\n";
        foreach ($headers as $k => $v) {
            $req .= "{$k}: {$v}\r\n";
        }
        $req .= "\r\n";
        @fwrite($this->stream, $req);

        $response = '';
        while (strpos($response, "\r\n\r\n") === false) {
            $chunk = @fread($this->stream, 2048);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        if (!str_contains($response, '200 OK')) {
            @fclose($this->stream);
            $this->stream = null;
            throw SseException::invalidEvent('SSE 握手失败', ['response' => substr($response, 0, 200)]);
        }

        return new SseClientConnection(
            SseConnection::generateId('sse'),
            'sse',
            stream_socket_get_name($this->stream, true) ?: "{$host}:{$port}",
            $this->stream,
            $this->logger,
        );
    }

    public function listen(string $host, int $port): void
    {
        throw new \LogicException('SSE Client 不支持 listen()');
    }

    public function run(): void
    {
        // 客户端在 Builder::loop() 中调用 read loop
    }

    public static function autoRegister(): void
    {
        Registry::register('sse', self::class);
    }
}
