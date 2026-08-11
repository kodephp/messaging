<?php

declare(strict_types=1);

namespace Kode\Messaging\Transport;

/**
 * 传输层接口 —— 统一抽象 TCP/UDP socket 操作。
 *
 * 所有协议适配器通过此接口操作底层 socket，不感知具体运行时
 * （stream / swoole / swow / workerman）。
 *
 * 驱动类型常量（PHP 8.3 基线：可直接使用 typed class constants）：
 *   - stream      纯 PHP stream 函数（始终可用，基准实现）
 *   - sockets     ext-sockets 扩展
 *   - swoole      Swoole 协程 Socket
 *   - swow        Swow 协程 Socket
 *   - workerman   Workerman 事件循环连接
 */
interface TransportInterface
{
    /** 纯 PHP stream 驱动（基准实现，始终可用） */
    public const DRIVER_STREAM = 'stream';

    /** ext-sockets 扩展驱动 */
    public const DRIVER_SOCKETS = 'sockets';

    /** Swoole 协程驱动 */
    public const DRIVER_SWOOLE = 'swoole';

    /** Swow 协程驱动 */
    public const DRIVER_SWOW = 'swow';

    /** Workerman 事件循环驱动 */
    public const DRIVER_WORKERMAN = 'workerman';

    /**
     * 创建服务端 socket 并监听。
     *
     * @param string $host     监听地址，如 "0.0.0.0" 或 "[::1]"
     * @param int    $port     监听端口
     * @param string $protocol 协议类型："tcp" 或 "udp"
     *
     * @return mixed 服务端 socket 句柄（resource 或对象，取决于驱动）
     *
     * @throws \Kode\Messaging\Exception\TransportException 监听失败
     */
    public function createServer(string $host, int $port, string $protocol = 'tcp'): mixed;

    /**
     * 接受一个新连接（阻塞或非阻塞取决于 socket 设置）。
     *
     * @param mixed $serverSocket 由 createServer() 返回的服务端 socket
     *
     * @return mixed 客户端 socket 句柄；无连接时返回 false
     */
    public function accept(mixed $serverSocket): mixed;

    /**
     * 创建客户端连接。
     *
     * @param string $host     目标地址
     * @param int    $port     目标端口
     * @param string $protocol 协议类型："tcp" 或 "udp"
     * @param float  $timeout  连接超时（秒）
     *
     * @return mixed 客户端 socket 句柄
     *
     * @throws \Kode\Messaging\Exception\TransportException 连接失败
     */
    public function createClient(string $host, int $port, string $protocol = 'tcp', float $timeout = 5.0): mixed;

    /**
     * 从 socket 读取数据。
     *
     * @param mixed $socket  socket 句柄
     * @param int   $length  期望读取的最大字节数
     *
     * @return false|string 读取到的数据；连接关闭或出错返回 false
     */
    public function read(mixed $socket, int $length): string|false;

    /**
     * 向 socket 写入数据。
     *
     * @param mixed  $socket socket 句柄
     * @param string $data   待写入数据
     *
     * @return false|int 实际写入字节数；出错返回 false
     */
    public function write(mixed $socket, string $data): int|false;

    /**
     * 关闭 socket。
     *
     * @param mixed $socket socket 句柄
     */
    public function close(mixed $socket): void;

    /**
     * 多路复用 select —— 等待 socket 可读/可写。
     *
     * @param array<int, mixed> $read               监听可读的 socket 数组
     * @param null|array<int, mixed> $write         监听可写的 socket 数组
     * @param int                $timeoutMicroseconds 超时（微秒）；0 表示立即返回
     *
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}|false
     *     返回 [可读数组, 可写数组]；出错或超时返回 false
     */
    public function select(array $read, ?array $write, int $timeoutMicroseconds): array|false;

    /**
     * 将 socket 设置为非阻塞模式。
     *
     * @param mixed $socket socket 句柄
     */
    public function setNonBlocking(mixed $socket): void;

    /**
     * 将 socket 设置为阻塞模式。
     *
     * @param mixed $socket socket 句柄
     */
    public function setBlocking(mixed $socket): void;

    /**
     * 获取对端地址（ip:port）。
     *
     * @param mixed $socket socket 句柄
     *
     * @return false|string 对端地址；失败返回 false
     */
    public function getPeerName(mixed $socket): string|false;

    /**
     * 返回当前驱动名称。
     *
     * @return string 见 DRIVER_* 常量
     */
    public function driver(): string;
}
