<?php

declare(strict_types=1);

namespace Kode\Messaging\Transport;

use Kode\Messaging\Exception\TransportException;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/**
 * 基于 Workerman 事件循环的传输层实现。
 *
 * Workerman 拥有自己的事件循环（EventLoop），与同步 stream 模型不同：
 *   - 服务端通过 Worker + onConnect / onMessage 回调驱动
 *   - 客户端通过 AsyncTcpConnection 异步连接
 *
 * 本实现将 Workerman 的连接对象包装为 TransportInterface 兼容形态。
 * 注意：select() 在 Workerman 模型下不适用（由事件循环调度），
 * 调用方应使用回调而非 select 轮询。
 *
 * 要求：workerman/workerman >= 5.0
 */
final class WorkermanTransport implements TransportInterface
{
    /**
     * 构造时检测 Workerman 是否可用。
     *
     * @throws TransportException 当 workerman/workerman 未安装
     */
    public function __construct()
    {
        if (!class_exists(Worker::class)) {
            throw TransportException::openFailed(
                'workerman',
                'workerman/workerman 未安装或 \Workerman\Worker 类不存在',
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * 创建 Workerman Worker 实例并开始监听。
     * 返回的 Worker 对象需由调用方进一步配置 onConnect / onMessage 回调，
     * 然后调用 Worker::runAll() 启动事件循环。
     *
     * @param string $host     监听地址
     * @param int    $port     监听端口
     * @param string $protocol "tcp" 或 "udp"
     *
     * @return Worker Workerman Worker 实例
     *
     * @throws TransportException 监听失败
     */
    public function createServer(string $host, int $port, string $protocol = 'tcp'): mixed
    {
        $scheme = $protocol === 'udp' ? 'udp' : 'tcp';
        $socketName = "{$scheme}://{$host}:{$port}";

        try {
            $worker = new Worker($socketName);
            $worker->count = 1;
            // 不立即 runAll，由调用方配置回调后启动
            return $worker;
        } catch (\Throwable $e) {
            throw TransportException::openFailed(
                $socketName,
                $e->getMessage(),
                ['host' => $host, 'port' => $port, 'protocol' => $protocol],
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * Workerman 使用事件循环回调模型，不使用同步 accept()。
     * 调用方应通过 Worker->onConnect 回调获取新连接。
     *
     * @param Worker $serverSocket Worker 实例
     *
     * @return false 始终返回 false（Workerman 由事件循环驱动）
     */
    public function accept(mixed $serverSocket): mixed
    {
        // Workerman 通过 onConnect 回调处理新连接，不使用同步 accept
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * 创建 Workerman AsyncTcpConnection 异步客户端连接。
     * 注意：连接实际建立需调用 $conn->connect() 并在事件循环中完成。
     *
     * @param string $host     目标地址
     * @param int    $port     目标端口
     * @param string $protocol "tcp"（Workerman 客户端仅支持 TCP）
     * @param float  $timeout  连接超时（秒，Workerman 内部处理）
     *
     * @return AsyncTcpConnection 异步连接对象
     *
     * @throws TransportException 连接失败
     */
    public function createClient(string $host, int $port, string $protocol = 'tcp', float $timeout = 5.0): mixed
    {
        if ($protocol === 'udp') {
            // Workerman 的 AsyncTcpConnection 不直接支持 UDP 客户端
            // UDP 客户端建议使用 StreamTransport
            throw TransportException::openFailed(
                "udp://{$host}:{$port}",
                'Workerman 客户端不支持 UDP，请使用 StreamTransport',
                ['host' => $host, 'port' => $port, 'protocol' => $protocol],
            );
        }

        $socketName = "tcp://{$host}:{$port}";

        try {
            $connection = new AsyncTcpConnection($socketName);
            // 设置连接超时（Workerman 内部以秒为单位）
            $connection->connectTimeout = $timeout;

            return $connection;
        } catch (\Throwable $e) {
            throw TransportException::openFailed(
                $socketName,
                $e->getMessage(),
                ['host' => $host, 'port' => $port, 'protocol' => $protocol],
            );
        }
    }

    /**
     * {@inheritdoc}
     *
     * Workerman 连接的读取由 onMessage 回调驱动，不使用同步 read()。
     * 此方法仅用于接口兼容，实际数据通过回调获取。
     *
     * @param TcpConnection|AsyncTcpConnection $socket 连接对象
     * @param int                               $length 期望读取的最大字节数
     *
     * @return false 始终返回 false（Workerman 由事件循环驱动）
     */
    public function read(mixed $socket, int $length): string|false
    {
        // Workerman 通过 onMessage 回调接收数据，不使用同步 read
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * 通过 Workerman 连接对象发送数据。
     *
     * @param TcpConnection|AsyncTcpConnection $socket 连接对象
     * @param string                            $data   待写入数据
     *
     * @return int|false 实际发送字节数；失败返回 false/null（统一为 false）
     */
    public function write(mixed $socket, string $data): int|false
    {
        $result = $socket->send($data);

        return $result === null || $result === false ? false : (int)$result;
    }

    /**
     * {@inheritdoc}
     *
     * 关闭 Workerman 连接或停止 Worker。
     *
     * @param TcpConnection|AsyncTcpConnection|Worker $socket 连接或 Worker 对象
     */
    public function close(mixed $socket): void
    {
        try {
            if ($socket instanceof Worker) {
                $socket->stopAll();
            } else {
                $socket->close();
            }
        } catch (\Throwable) {
            // 忽略关闭错误
        }
    }

    /**
     * {@inheritdoc}
     *
     * Workerman 使用事件循环，不使用 select() 多路复用。
     * 调用方应使用 Worker->runAll() 启动事件循环。
     *
     * @param array<int, mixed>      $read
     * @param array<int, mixed>|null $write
     * @param int                    $timeoutMicroseconds
     *
     * @return false 始终返回 false（Workerman 由事件循环驱动）
     */
    public function select(array $read, ?array $write, int $timeoutMicroseconds): array|false
    {
        // Workerman 由事件循环调度，不使用 select
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Workerman 连接由事件循环管理，无需手动设置非阻塞。
     *
     * @param TcpConnection|AsyncTcpConnection $socket 连接对象
     */
    public function setNonBlocking(mixed $socket): void
    {
        // Workerman 连接由事件循环管理，无需手动设置非阻塞
        // 保留方法以满足接口契约
    }

    /**
     * {@inheritdoc}
     *
     * @param TcpConnection|AsyncTcpConnection $socket 连接对象
     */
    public function setBlocking(mixed $socket): void
    {
        // Workerman 连接由事件循环管理，无需手动设置阻塞
        // 保留方法以满足接口契约
    }

    /**
     * {@inheritdoc}
     *
     * @param TcpConnection|AsyncTcpConnection $socket 连接对象
     *
     * @return string|false
     */
    public function getPeerName(mixed $socket): string|false
    {
        $ip = $socket->getRemoteIp();
        $port = $socket->getRemotePort();

        if ($ip === null || $ip === '' || $port === null) {
            return false;
        }

        return "{$ip}:{$port}";
    }

    /**
     * {@inheritdoc}
     */
    public function driver(): string
    {
        return self::DRIVER_WORKERMAN;
    }
}
