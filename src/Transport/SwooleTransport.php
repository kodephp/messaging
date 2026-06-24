<?php

declare(strict_types=1);

namespace Kode\Messaging\Transport;

use Kode\Messaging\Exception\TransportException;
use Swoole\Coroutine\Socket as SwooleSocket;
use Swoole\Coroutine\System;

/**
 * 基于 Swoole 协程 Socket 的传输层实现。
 *
 * 使用 \Swoole\Coroutine\Socket 进行服务端/客户端 socket 操作，
 * 所有方法须在 Swoole 协程上下文（\Swoole\Coroutine\run 或 OneShot）内调用。
 *
 * 优势：
 *   - 百万级并发连接
 *   - 协程调度自动让出 CPU，无需手写 select 循环
 *   - 与 kode/fibers 协作友好
 *
 * 要求：ext-swoole >= 4.5（建议 5.x）
 */
final class SwooleTransport implements TransportInterface
{
    /**
     * 构造时检测 Swoole 是否可用。
     *
     * @throws TransportException 当 ext-swoole 未加载或 \Swoole\Coroutine\Socket 不存在
     */
    public function __construct()
    {
        if (!class_exists(SwooleSocket::class)) {
            throw TransportException::openFailed(
                'swoole',
                'ext-swoole 未加载或 \Swoole\Coroutine\Socket 类不存在',
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param string $host     监听地址
     * @param int    $port     监听端口
     * @param string $protocol "tcp" 或 "udp"
     *
     * @return SwooleSocket 服务端 socket 对象
     *
     * @throws TransportException 监听失败
     */
    public function createServer(string $host, int $port, string $protocol = 'tcp'): mixed
    {
        [$domain, $type, $proto] = $this->mapProtocol($protocol);

        $socket = new SwooleSocket($domain, $type, $proto);
        $socket->setOption(SOL_SOCKET, SO_REUSEADDR, 1);

        if (!$socket->bind($host, $port)) {
            throw TransportException::openFailed(
                "{$protocol}://{$host}:{$port}",
                $socket->errMsg ?: 'bind 失败',
                ['host' => $host, 'port' => $port, 'protocol' => $protocol, 'errno' => $socket->errCode],
            );
        }

        // TCP 需要 listen；UDP 不需要
        if ($protocol !== 'udp' && !$socket->listen(512)) {
            throw TransportException::openFailed(
                "{$protocol}://{$host}:{$port}",
                $socket->errMsg ?: 'listen 失败',
                ['host' => $host, 'port' => $port, 'protocol' => $protocol, 'errno' => $socket->errCode],
            );
        }

        return $socket;
    }

    /**
     * {@inheritdoc}
     *
     * @param SwooleSocket $serverSocket 服务端 socket 对象
     *
     * @return SwooleSocket|false 客户端 socket 对象；无连接返回 false
     */
    public function accept(mixed $serverSocket): mixed
    {
        // 超时 0 表示非阻塞尝试（协程内仍会自动调度）
        return $serverSocket->accept(0);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $host     目标地址
     * @param int    $port     目标端口
     * @param string $protocol "tcp" 或 "udp"
     * @param float  $timeout  连接超时（秒）
     *
     * @return SwooleSocket 客户端 socket 对象
     *
     * @throws TransportException 连接失败
     */
    public function createClient(string $host, int $port, string $protocol = 'tcp', float $timeout = 5.0): mixed
    {
        [$domain, $type, $proto] = $this->mapProtocol($protocol);

        $socket = new SwooleSocket($domain, $type, $proto);

        if (!$socket->connect($host, $port, $timeout)) {
            throw TransportException::openFailed(
                "{$protocol}://{$host}:{$port}",
                $socket->errMsg ?: 'connect 失败',
                ['host' => $host, 'port' => $port, 'protocol' => $protocol, 'errno' => $socket->errCode],
            );
        }

        return $socket;
    }

    /**
     * {@inheritdoc}
     *
     * @param SwooleSocket $socket socket 对象
     * @param int          $length 期望读取的最大字节数
     *
     * @return string|false
     */
    public function read(mixed $socket, int $length): string|false
    {
        if ($length <= 0) {
            return false;
        }

        // 超时 -1 表示使用默认；这里用 0 表示非阻塞尝试
        return $socket->recv($length, 0);
    }

    /**
     * {@inheritdoc}
     *
     * @param SwooleSocket $socket socket 对象
     * @param string       $data   待写入数据
     *
     * @return int|false
     */
    public function write(mixed $socket, string $data): int|false
    {
        return $socket->send($data, 0);
    }

    /**
     * {@inheritdoc}
     *
     * @param SwooleSocket $socket socket 对象
     */
    public function close(mixed $socket): void
    {
        $socket->close();
    }

    /**
     * {@inheritdoc}
     *
     * Swoole 协程模型下，并发由协程调度自动处理，select() 语义与 stream 不同。
     * 这里采用轮询方式：对每个 socket 以 0 超时尝试 recv 检测可读性。
     *
     * @param array<int, SwooleSocket>      $read
     * @param array<int, SwooleSocket>|null $write
     * @param int                           $timeoutMicroseconds
     *
     * @return array{0: array<int, SwooleSocket>, 1: array<int, SwooleSocket>}|false
     */
    public function select(array $read, ?array $write, int $timeoutMicroseconds): array|false
    {
        if ($read === [] && ($write === null || $write === [])) {
            return [[], []];
        }

        $readable = [];
        $writable = $write ?? [];

        // 协程模型下，select 退化为轮询检测可读
        foreach ($read as $socket) {
            $peek = $socket->recv(1, 0);
            if ($peek !== false && $peek !== '') {
                $readable[] = $socket;
            } elseif ($peek === '') {
                // 空字符串表示连接已关闭，也视为"可读"以便上层处理 EOF
                $readable[] = $socket;
            }
        }

        // 若无就绪 socket 且设置了超时，则协程 sleep 后再返回（让出调度）
        if ($readable === [] && $timeoutMicroseconds > 0) {
            System::sleep($timeoutMicroseconds / 1_000_000);
        }

        return [$readable, $writable];
    }

    /**
     * {@inheritdoc}
     *
     * Swoole 协程 Socket 由协程调度器管理阻塞，本身即为"协程非阻塞"。
     * 此方法为接口兼容的空操作；如需真正非阻塞可调用 setDefer(false)。
     *
     * @param SwooleSocket $socket socket 对象
     */
    public function setNonBlocking(mixed $socket): void
    {
        // 协程 Socket 默认由调度器管理，无需手动设置非阻塞
        // 保留方法以满足接口契约
    }

    /**
     * {@inheritdoc}
     *
     * @param SwooleSocket $socket socket 对象
     */
    public function setBlocking(mixed $socket): void
    {
        // 协程 Socket 默认由调度器管理，无需手动设置阻塞
        // 保留方法以满足接口契约
    }

    /**
     * {@inheritdoc}
     *
     * @param SwooleSocket $socket socket 对象
     *
     * @return string|false
     */
    public function getPeerName(mixed $socket): string|false
    {
        $info = $socket->getpeername();

        if ($info === false || !isset($info['host'], $info['port'])) {
            return false;
        }

        return "{$info['host']}:{$info['port']}";
    }

    /**
     * {@inheritdoc}
     */
    public function driver(): string
    {
        return self::DRIVER_SWOOLE;
    }

    /**
     * 将协议名映射为 Swoole Socket 构造参数。
     *
     * @param string $protocol "tcp" 或 "udp"
     *
     * @return array{0: int, 1: int, 2: int} [domain, type, protocol]
     */
    private function mapProtocol(string $protocol): array
    {
        return match ($protocol) {
            'udp' => [AF_INET, SOCK_DGRAM, SOL_UDP],
            'unix' => [AF_UNIX, SOCK_STREAM, 0],
            default => [AF_INET, SOCK_STREAM, SOL_TCP],
        };
    }
}
