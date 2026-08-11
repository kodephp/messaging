<?php

declare(strict_types=1);

namespace Kode\Messaging\Transport;

use Kode\Messaging\Exception\TransportException;
use Swow\Socket as SwowSocket;
use Throwable;

/**
 * 基于 Swow 协程 Socket 的传输层实现。
 *
 * 使用 \Swow\Socket 进行服务端/客户端 socket 操作，
 * 所有方法须在 Swow 协程上下文内调用。
 *
 * 优势：
 *   - 跨平台协程（Windows / macOS / Linux 均可）
 *   - 纯 C 扩展，性能接近 Swoole
 *   - 与 PHP 原生扩展生态兼容性好
 *
 * 要求：ext-swow >= 1.5
 */
final class SwowTransport implements TransportInterface
{
    /**
     * 构造时检测 Swow 是否可用。
     *
     * @throws TransportException 当 ext-swow 未加载或 \Swow\Socket 类不存在
     */
    public function __construct()
    {
        if (! class_exists(SwowSocket::class)) {
            throw TransportException::openFailed(
                'swow',
                'ext-swow 未加载或 \Swow\Socket 类不存在',
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
     * @return SwowSocket 服务端 socket 对象
     *
     * @throws TransportException 监听失败
     */
    public function createServer(string $host, int $port, string $protocol = 'tcp'): mixed
    {
        $type = $this->mapType($protocol);

        $socket = new SwowSocket($type);
        $socket->setTcpNodelay(true);

        try {
            $socket->bind("{$host}:{$port}");
        } catch (Throwable $e) {
            throw TransportException::openFailed(
                "{$protocol}://{$host}:{$port}",
                $e->getMessage(),
                ['host' => $host, 'port' => $port, 'protocol' => $protocol],
            );
        }

        // TCP 需要 listen；UDP 不需要
        if ($protocol !== 'udp') {
            try {
                $socket->listen(512);
            } catch (Throwable $e) {
                throw TransportException::openFailed(
                    "{$protocol}://{$host}:{$port}",
                    $e->getMessage(),
                    ['host' => $host, 'port' => $port, 'protocol' => $protocol],
                );
            }
        }

        return $socket;
    }

    /**
     * {@inheritdoc}
     *
     * @param SwowSocket $serverSocket 服务端 socket 对象
     *
     * @return false|SwowSocket 客户端 socket 对象；无连接返回 false
     */
    public function accept(mixed $serverSocket): mixed
    {
        try {
            return $serverSocket->accept();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param string $host     目标地址
     * @param int    $port     目标端口
     * @param string $protocol "tcp" 或 "udp"
     * @param float  $timeout  连接超时（秒，Swow 由协程调度管理）
     *
     * @return SwowSocket 客户端 socket 对象
     *
     * @throws TransportException 连接失败
     */
    public function createClient(string $host, int $port, string $protocol = 'tcp', float $timeout = 5.0): mixed
    {
        $type = $this->mapType($protocol);
        $socket = new SwowSocket($type);

        try {
            $socket->connect("{$host}:{$port}");
        } catch (Throwable $e) {
            throw TransportException::openFailed(
                "{$protocol}://{$host}:{$port}",
                $e->getMessage(),
                ['host' => $host, 'port' => $port, 'protocol' => $protocol],
            );
        }

        return $socket;
    }

    /**
     * {@inheritdoc}
     *
     * @param SwowSocket $socket socket 对象
     * @param int        $length 期望读取的最大字节数
     */
    public function read(mixed $socket, int $length): string|false
    {
        if ($length <= 0) {
            return false;
        }

        try {
            return $socket->recvString($length);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param SwowSocket $socket socket 对象
     * @param string     $data   待写入数据
     */
    public function write(mixed $socket, string $data): int|false
    {
        try {
            return $socket->sendString($data);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param SwowSocket $socket socket 对象
     */
    public function close(mixed $socket): void
    {
        try {
            $socket->close();
        } catch (Throwable) {
            // 忽略关闭错误
        }
    }

    /**
     * {@inheritdoc}
     *
     * Swow 协程模型下，并发由协程调度自动处理。
     * select() 退化为轮询检测可读性。
     *
     * @param array<int, SwowSocket>      $read
     * @param null|array<int, SwowSocket> $write
     *
     * @return array{0: array<int, SwowSocket>, 1: array<int, SwowSocket>}|false
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
            try {
                $peek = $socket->recvString(1, SwowSocket::READ_PEEK);
                if ($peek !== '') {
                    $readable[] = $socket;
                }
            } catch (Throwable) {
                // 出错视为可读以便上层处理 EOF
                $readable[] = $socket;
            }
        }

        // 若无就绪 socket 且设置了超时，则协程 sleep 后再返回（让出调度）
        if ($readable === [] && $timeoutMicroseconds > 0) {
            \Swow\Coroutine::sleep($timeoutMicroseconds / 1_000_000);
        }

        return [$readable, $writable];
    }

    /**
     * {@inheritdoc}
     *
     * Swow 协程 Socket 由协程调度器管理阻塞，本身即为"协程非阻塞"。
     *
     * @param SwowSocket $socket socket 对象
     */
    public function setNonBlocking(mixed $socket): void
    {
        // 协程 Socket 默认由调度器管理，无需手动设置非阻塞
        // 保留方法以满足接口契约
    }

    /**
     * {@inheritdoc}
     *
     * @param SwowSocket $socket socket 对象
     */
    public function setBlocking(mixed $socket): void
    {
        // 协程 Socket 默认由调度器管理，无需手动设置阻塞
        // 保留方法以满足接口契约
    }

    /**
     * {@inheritdoc}
     *
     * @param SwowSocket $socket socket 对象
     */
    public function getPeerName(mixed $socket): string|false
    {
        try {
            $name = $socket->getPeerName();
            $address = $socket->getPeerAddress();
            $port = $socket->getPeerPort();

            if ($address !== '' && $port > 0) {
                return "{$address}:{$port}";
            }

            return $name !== '' ? $name : false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function driver(): string
    {
        return self::DRIVER_SWOW;
    }

    /**
     * 将协议名映射为 Swow Socket 类型常量。
     *
     * @param string $protocol "tcp" 或 "udp"
     *
     * @return int Swow\Socket::TYPE_* 常量
     */
    private function mapType(string $protocol): int
    {
        return match ($protocol) {
            'udp' => SwowSocket::TYPE_UDP,
            'unix' => SwowSocket::TYPE_UNIX,
            default => SwowSocket::TYPE_TCP,
        };
    }
}
