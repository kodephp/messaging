<?php

declare(strict_types=1);

namespace Kode\Messaging;

use InvalidArgumentException;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Client\Builder as ClientBuilder;
use Kode\Messaging\PubSub\Bus;
use Kode\Messaging\PubSub\MemoryBus;
use Kode\Messaging\Server\Builder as ServerBuilder;
use Kode\Messaging\Support\Version;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * kode/messaging 静态入口门面
 *
 * 用例：
 *   Messaging::server('ws://0.0.0.0:8080')->on('message.received', $h)->start();
 *   Messaging::client('mqtt://broker:1883')->subscribe('sensors/#', $h)->connect()->loop();
 *   Messaging::pubsub('redis')->publish('orders:created', $data);
 *
 * 设计原则：
 *  - 静态方法不持有状态（除全局单例外）
 *  - 构造复杂对象时返回 Builder
 *  - 与 kode/process 风格保持一致
 */
final class Messaging
{
    /** @var array<string, mixed> */
    private static array $config = [];

    private static ?LoggerInterface $logger = null;

    /** @var list<object> */
    private static array $globalMiddlewares = [];

    private function __construct() {}

    /**
     * 加载全局配置。
     *
     * @param array<string, mixed> $config
     */
    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /**
     * 设置全局日志器。
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    public static function logger(): LoggerInterface
    {
        return self::$logger ??= new NullLogger();
    }

    /**
     * 推入全局中间件（对所有 server / client 生效）。
     */
    public static function pushMiddleware(object $middleware): void
    {
        self::$globalMiddlewares[] = $middleware;
    }

    /**
     * 获取全局中间件。
     *
     * @return list<object>
     */
    public static function globalMiddlewares(): array
    {
        return self::$globalMiddlewares;
    }

    public static function version(): string
    {
        return Version::get();
    }

    /**
     * 创建一个服务端构建器。
     *
     * @param array<string, mixed> $config 协议特定配置
     */
    public static function server(string $scheme, array $config = []): ServerBuilder
    {
        $scheme = self::normalizeScheme($scheme);

        return new ServerBuilder($scheme, $config, self::$config);
    }

    /**
     * 创建一个客户端构建器。
     *
     * @param array<string, mixed> $config 协议特定配置
     */
    public static function client(string $scheme, array $config = []): ClientBuilder
    {
        $scheme = self::normalizeScheme($scheme);

        return new ClientBuilder($scheme, $config, self::$config);
    }

    /**
     * 创建一个发布订阅总线。
     *
     * @param null|string $driver memory | channel | redis
     * @param array<string, mixed> $config 驱动配置
     */
    public static function pubsub(?string $driver = null, array $config = []): Bus
    {
        $driver ??= self::$config['pubsub']['default'] ?? 'memory';

        return match ($driver) {
            'redis' => new PubSub\RedisBus(
                array_replace_recursive(self::$config['pubsub']['redis'] ?? [], $config),
                self::logger(),
            ),
            'channel' => new PubSub\ChannelBus(
                array_replace_recursive(self::$config['pubsub']['channel'] ?? [], $config),
                self::logger(),
            ),
            default => new MemoryBus($config, self::logger()),
        };
    }

    /**
     * 解析 URL，提取 scheme / host / port。
     *
     * @return array{scheme: string, host: string, port: int, path: string, query: array<string, string>, tls: bool}
     */
    public static function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'])) {
            throw new InvalidArgumentException("无法解析 URL: {$url}");
        }
        $scheme = strtolower($parts['scheme']);
        // ws / wss 归一为 ws，tls 由后缀 s 决定
        $tls = false;
        $base = $scheme;
        if (str_ends_with($scheme, 's') && in_array($scheme, ['wss', 'mqtts', 'https'], true)) {
            $tls = true;
            $base = match ($scheme) {
                'wss' => 'ws',
                'mqtts' => 'mqtt',
                'https' => 'sse',
            };
        }
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return [
            'scheme' => $base,
            'host' => $parts['host'] ?? '0.0.0.0',
            'port' => $parts['port'] ?? self::defaultPort($base, $tls),
            'path' => $parts['path'] ?? '/',
            'query' => $query,
            'tls' => $tls,
        ];
    }

    /**
     * 解析 scheme 的默认端口。
     */
    public static function defaultPort(string $scheme, bool $tls = false): int
    {
        return match ($scheme) {
            'ws' => $tls ? 443 : 80,
            'mqtt' => $tls ? 8883 : 1883,
            'mqtt+ws' => $tls ? 8443 : 8083,
            'sse' => 8081,
            'udp' => 8082,
            'long-polling' => 8083,
            'coap' => $tls ? 5684 : 5683,
            'nats' => 4222,
            'stomp' => 61613,
            'grpc' => 50051,
            'webtransport' => 4433,
            'rtmp' => 1935,
            default => 0,
        };
    }

    /**
     * 把 ws / WS / websocket 等变体归一到注册表使用的 key。
     */
    public static function normalizeScheme(string $scheme): string
    {
        $scheme = strtolower(trim($scheme));

        return match (true) {
            in_array($scheme, ['ws', 'wss', 'websocket', 'websockets'], true) => 'ws',
            in_array($scheme, ['sse', 'eventsource', 'event-stream'], true) => 'sse',
            in_array($scheme, ['mqtt', 'mqtts', 'mqttv3', 'mqttv5'], true) => 'mqtt',
            in_array($scheme, ['mqtt+ws', 'mqtt+wss', 'ws+mqtt', 'wss+mqtt'], true) => 'mqtt+ws',
            in_array($scheme, ['udp', 'datagram', 'dgram'], true) => 'udp',
            in_array($scheme, ['poll', 'long-polling', 'longpolling', 'lp'], true) => 'long-polling',
            in_array($scheme, ['coap', 'coaps'], true) => 'coap',
            in_array($scheme, ['nats', 'nats://'], true) => 'nats',
            in_array($scheme, ['stomp', 'stomps'], true) => 'stomp',
            in_array($scheme, ['grpc', 'grpc-web', 'grpcweb'], true) => 'grpc',
            in_array($scheme, ['webtransport', 'wt'], true) => 'webtransport',
            in_array($scheme, ['rtmp', 'rtmps'], true) => 'rtmp',
            default => $scheme,
        };
    }

    /**
     * 注册协议适配器（业务方扩展协议时调用）。
     *
     * @param class-string<Contract\AdapterInterface> $adapterClass
     */
    public static function register(string $scheme, string $adapterClass): void
    {
        Registry::register(self::normalizeScheme($scheme), $adapterClass);
    }

    /**
     * 列出已注册协议。
     *
     * @return list<string>
     */
    public static function schemes(): array
    {
        return Registry::schemes();
    }
}
