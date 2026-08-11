<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Grpc;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\GrpcException;
use Kode\Messaging\Server\Builder as ServerBuilder;
use LogicException;
use Throwable;

/**
 * gRPC 简易服务端
 *
 * 处理 HTTP/1.1 + chunked TE 的 gRPC 风格请求：
 *  - 解析 path → method
 *  - 解析 gRPC 帧
 *  - 业务层注册 method handler
 *  - 响应 chunked 编码 + trailers
 */
final class Server extends AbstractAdapter
{
    /** @var null|resource */
    private $socket = null;

    /** @var array<string, callable(string, array): string> */
    private array $methodHandlers = [];

    /** @var array<int, resource> 已接受的连接（fd → stream） */
    private array $clientSocks = [];

    /** @var array<int, array{path: string, headers: array<string, string>, body: string, buffer: string}> fd → 解析中 */
    private array $pending = [];

    private int $fdSeq = 1;

    private ?ServerBuilder $builder = null;

    public static function scheme(): string
    {
        return 'grpc';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    public function builder(): ?ServerBuilder
    {
        return $this->builder;
    }

    public function registerMethod(string $path, callable $handler): self
    {
        $this->methodHandlers[$path] = $handler;

        return $this;
    }

    protected function defaultConfig(): array
    {
        return [
            'max_body_size' => 4 * 1024 * 1024,
        ];
    }

    public function listen(string $host, int $port): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if ($this->socket === false) {
            throw GrpcException::http2Error("listen 失败: {$errstr}");
        }
        stream_set_blocking($this->socket, false);
        $this->logger->info("gRPC listening on {$host}:{$port}");
    }

    public function run(): void
    {
        $this->running = true;
        while ($this->running) {
            $new = @stream_socket_accept($this->socket, 0);
            if ($new !== false) {
                $fd = $this->fdSeq++;
                $this->clientSocks[$fd] = $new;
                $this->pending[$fd] = ['path' => '', 'headers' => [], 'body' => '', 'buffer' => ''];
            }

            foreach ($this->clientSocks as $fd => $sock) {
                $chunk = @fread($sock, 4096);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $this->pending[$fd]['buffer'] .= $chunk;
                $this->tryHandleRequest($fd);
            }

            usleep(1_000);
        }
    }

    public function connect(array $config): ConnectionInterface
    {
        throw new LogicException('gRPC Server 不支持 connect()');
    }

    public function shutdown(): void
    {
        $this->running = false;
        foreach ($this->clientSocks as $sock) {
            @fclose($sock);
        }
        $this->clientSocks = [];
        $this->pending = [];
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('grpc', self::class);
    }

    private function tryHandleRequest(int $fd): void
    {
        $sock = $this->clientSocks[$fd] ?? null;
        if (! is_resource($sock)) {
            return;
        }
        $state = &$this->pending[$fd];
        $buf = &$state['buffer'];

        $headerEnd = strpos($buf, "\r\n\r\n");
        if ($headerEnd === false) {
            return;
        }
        $headerPart = substr($buf, 0, $headerEnd);
        $body = substr($buf, $headerEnd + 4);
        $headers = $this->parseRequestHeaders($headerPart);
        if (! isset($headers['__method']) || ! isset($headers['__path'])) {
            @fclose($sock);
            unset($this->clientSocks[$fd], $this->pending[$fd]);

            return;
        }
        $method = $headers['__method'];
        $path = $headers['__path'];

        if ($method !== 'POST') {
            $this->respondError($sock, 405, GrpcCodec::STATUS_UNIMPLEMENTED, '仅支持 POST');
            unset($this->clientSocks[$fd], $this->pending[$fd]);

            return;
        }

        // 等待 body：chunked 编码
        if (stripos($headers['transfer-encoding'] ?? '', 'chunked') !== false) {
            $body = $this->decodeChunked($body, $headers, $sock);
            if ($body === null) {
                return; // 等更多数据
            }
        } elseif (isset($headers['content-length'])) {
            $cl = (int) $headers['content-length'];
            if (strlen($body) < $cl) {
                return; // 等更多数据
            }
            $body = substr($body, 0, $cl);
        } else {
            $this->respondError($sock, 411, GrpcCodec::STATUS_INVALID_ARGUMENT, '缺少 Content-Length');
            unset($this->clientSocks[$fd], $this->pending[$fd]);

            return;
        }

        // 解析 gRPC 帧
        $frame = GrpcCodec::decode($body);
        if ($frame === null) {
            $this->respondError($sock, 400, GrpcCodec::STATUS_INVALID_ARGUMENT, 'gRPC 帧解析失败');
            unset($this->clientSocks[$fd], $this->pending[$fd]);

            return;
        }
        $payload = $frame['payload'];
        unset($this->pending[$fd]);

        // 路由
        $handler = $this->methodHandlers[$path] ?? null;
        if ($handler === null) {
            $this->respondError($sock, 404, GrpcCodec::STATUS_UNIMPLEMENTED, "未注册方法: {$path}");
            @fclose($sock);
            unset($this->clientSocks[$fd]);

            return;
        }

        $meta = [];
        foreach ($headers as $k => $v) {
            if (! str_starts_with($k, '__')) {
                $meta[$k] = $v;
            }
        }

        try {
            $responsePayload = $handler($payload, $meta);
        } catch (Throwable $e) {
            $this->respondError($sock, 500, GrpcCodec::STATUS_INTERNAL, $e->getMessage());
            @fclose($sock);
            unset($this->clientSocks[$fd]);

            return;
        }

        $this->respondOk($sock, $responsePayload);
        @fclose($sock);
        unset($this->clientSocks[$fd]);
    }

    private function parseRequestHeaders(string $raw): array
    {
        $lines = explode("\r\n", $raw);
        $headers = [];
        if (isset($lines[0]) && preg_match('#^([A-Z]+)\s+(\S+)(?:\s+HTTP/[\d.]+)?$#', $lines[0], $m)) {
            $headers['__method'] = $m[1];
            $headers['__path'] = $m[2];
            array_shift($lines);
        }
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $pos)));
            $val = trim(substr($line, $pos + 1));
            $headers[$key] = $val;
        }

        return $headers;
    }

    private function decodeChunked(string $body, array $headers, $sock): ?string
    {
        $decoded = '';
        $offset = 0;
        $len = strlen($body);
        while ($offset < $len) {
            $crlf = strpos($body, "\r\n", $offset);
            if ($crlf === false) {
                return null; // 等更多数据
            }
            $size = (int) hexdec(trim(substr($body, $offset, $crlf - $offset)));
            if ($size === 0) {
                return $decoded;
            }
            $offset = $crlf + 2;
            if (strlen($body) < $offset + $size + 2) {
                return null;
            }
            $decoded .= substr($body, $offset, $size);
            $offset += $size + 2;
        }

        return $decoded;
    }

    private function respondOk($sock, string $payload): void
    {
        $grpcBody = GrpcCodec::encode($payload);
        $chunked = dechex(strlen($grpcBody))."\r\n".$grpcBody."\r\n0\r\n".GrpcCodec::encodeTrailers(GrpcCodec::STATUS_OK)."\r\n";
        $headers = [
            'HTTP/1.1 200 OK',
            'Content-Type: '.GrpcCodec::contentType(),
            'Trailer: Grpc-Status, Grpc-Message',
            'Transfer-Encoding: chunked',
        ];
        @fwrite($sock, implode("\r\n", $headers)."\r\n\r\n".$chunked);
    }

    private function respondError($sock, int $http, int $grpcCode, string $msg): void
    {
        $headers = [
            "HTTP/1.1 {$http} Error",
            'Content-Type: '.GrpcCodec::contentType(),
            'Trailer: Grpc-Status, Grpc-Message',
            'Transfer-Encoding: chunked',
        ];
        $body = GrpcCodec::encodeTrailers($grpcCode, $msg)."\r\n";
        $chunked = dechex(strlen($body))."\r\n".$body."\r\n0\r\n\r\n";
        @fwrite($sock, implode("\r\n", $headers)."\r\n\r\n".$chunked);
    }
}
