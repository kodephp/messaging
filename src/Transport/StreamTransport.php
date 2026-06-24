<?php

declare(strict_types=1);

namespace Kode\Messaging\Transport;

use Kode\Messaging\Exception\TransportException;

/**
 * 基于 PHP 内建 stream 函数的传输层实现。
 *
 * 这是默认/回退实现，始终可用（无需任何扩展）。
 * 使用 stream_socket_server / stream_socket_accept / stream_socket_client
 * 以及 fread / fwrite / fclose / stream_select 等函数。
 *
 * 适合：教学、跨平台、零扩展依赖场景。
 * 生产环境高并发推荐 SwooleTransport 或 SwowTransport。
 */
final class StreamTransport implements TransportInterface
{
    /**
     * {@inheritdoc}
     *
     * @param string $host     监听地址
     * @param int    $port     监听端口
     * @param string $protocol "tcp" 或 "udp"
     *
     * @return resource 服务端 socket 资源
     *
     * @throws TransportException 监听失败
     */
    public function createServer(string $host, int $port, string $protocol = 'tcp'): mixed
    {
        $scheme = $this->normalizeScheme($protocol);
        $errno = 0;
        $errstr = '';

        $context = stream_context_create([
            'socket' => [
                'so_reuseaddr' => true,
                'so_reuseport' => true,
            ],
        ]);

        // UDP 只需 BIND；TCP 需要 BIND | LISTEN
        $flags = $protocol === 'udp'
            ? STREAM_SERVER_BIND
            : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

        $socket = @stream_socket_server(
            "{$scheme}://{$host}:{$port}",
            $errno,
            $errstr,
            $flags,
            $context,
        );

        if ($socket === false) {
            throw TransportException::openFailed(
                "{$scheme}://{$host}:{$port}",
                $errstr !== '' ? $errstr : "errno={$errno}",
                ['host' => $host, 'port' => $port, 'protocol' => $protocol, 'errno' => $errno],
            );
        }

        return $socket;
    }

    /**
     * {@inheritdoc}
     *
     * @param resource $serverSocket 服务端 socket 资源
     *
     * @return resource|false 客户端 socket 资源；无连接返回 false
     */
    public function accept(mixed $serverSocket): mixed
    {
        // 超时 0 表示非阻塞尝试
        return @stream_socket_accept($serverSocket, 0);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $host     目标地址
     * @param int    $port     目标端口
     * @param string $protocol "tcp" 或 "udp"
     * @param float  $timeout  连接超时（秒）
     *
     * @return resource 客户端 socket 资源
     *
     * @throws TransportException 连接失败
     */
    public function createClient(string $host, int $port, string $protocol = 'tcp', float $timeout = 5.0): mixed
    {
        $scheme = $this->normalizeScheme($protocol);
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            "{$scheme}://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw TransportException::openFailed(
                "{$scheme}://{$host}:{$port}",
                $errstr !== '' ? $errstr : "errno={$errno}",
                ['host' => $host, 'port' => $port, 'protocol' => $protocol, 'errno' => $errno],
            );
        }

        return $socket;
    }

    /**
     * {@inheritdoc}
     *
     * @param resource $socket socket 资源
     * @param int      $length 期望读取的最大字节数
     *
     * @return string|false
     */
    public function read(mixed $socket, int $length): string|false
    {
        if ($length <= 0) {
            return false;
        }

        return @fread($socket, $length);
    }

    /**
     * {@inheritdoc}
     *
     * @param resource $socket socket 资源
     * @param string   $data   待写入数据
     *
     * @return int|false
     */
    public function write(mixed $socket, string $data): int|false
    {
        return @fwrite($socket, $data);
    }

    /**
     * {@inheritdoc}
     *
     * @param resource $socket socket 资源
     */
    public function close(mixed $socket): void
    {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param array<int, resource>      $read
     * @param array<int, resource>|null $write
     * @param int                       $timeoutMicroseconds
     *
     * @return array{0: array<int, resource>, 1: array<int, resource>}|false
     */
    public function select(array $read, ?array $write, int $timeoutMicroseconds): array|false
    {
        if ($read === [] && ($write === null || $write === [])) {
            return [[], []];
        }

        $except = null;
        $seconds = (int) floor($timeoutMicroseconds / 1_000_000);
        $microseconds = $timeoutMicroseconds % 1_000_000;

        $readCopy = $read;
        $writeCopy = $write ?? [];

        $n = @stream_select($readCopy, $writeCopy, $except, $seconds, $microseconds);

        if ($n === false) {
            return false;
        }

        return [$readCopy, $writeCopy];
    }

    /**
     * {@inheritdoc}
     *
     * @param resource $socket socket 资源
     */
    public function setNonBlocking(mixed $socket): void
    {
        @stream_set_blocking($socket, false);
    }

    /**
     * {@inheritdoc}
     *
     * @param resource $socket socket 资源
     */
    public function setBlocking(mixed $socket): void
    {
        @stream_set_blocking($socket, true);
    }

    /**
     * {@inheritdoc}
     *
     * @param resource $socket socket 资源
     *
     * @return string|false
     */
    public function getPeerName(mixed $socket): string|false
    {
        $name = @stream_socket_get_name($socket, true);

        return $name === false ? false : $name;
    }

    /**
     * {@inheritdoc}
     */
    public function driver(): string
    {
        return self::DRIVER_STREAM;
    }

    /**
     * 将协议名规范化为 stream scheme。
     *
     * @param string $protocol "tcp" / "udp" / "ssl" / "tls"
     *
     * @return string
     */
    private function normalizeScheme(string $protocol): string
    {
        return match ($protocol) {
            'tcp', 'udp', 'ssl', 'tls', 'unix', 'udg' => $protocol,
            default => 'tcp',
        };
    }
}
