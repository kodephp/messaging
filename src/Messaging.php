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

    /** @var array<string, Bus> 进程级总线实例表，键为「驱动|配置指纹」 */
    private static array $buses = [];

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
     * 取得一个发布订阅总线（按「驱动 + 生效配置」缓存，同参数恒返回同一实例）。
     *
     * 为什么必须缓存：总线的订阅表挂在实例上，而框架侧 messaging()->bus() 每次调用都取总线。
     * 若这里每次 new，上一条调用里 subscribe 的处理器在这条调用里根本不存在——publish
     * 静默零投递，常驻 worker 里退订也无从谈起。需要一次性隔离的总线请自行 new MemoryBus()。
     *
     * @param null|string $driver memory | channel | redis
     * @param array<string, mixed> $config 驱动配置
     */
    public static function pubsub(?string $driver = null, array $config = []): Bus
    {
        $driver ??= (string) (self::$config['pubsub']['default'] ?? 'memory');
        /** @var array<string, mixed> $resolved */
        $resolved = array_replace_recursive(
            (array) (self::$config['pubsub'][$driver] ?? []),
            $config,
        );

        $signature = self::fingerprint($resolved);
        if ($signature === null) {
            // 配置里有对象/资源（闭包同理）：json_encode 会把它们静默压成 {}，
            // 两份不同意图的配置就会被并成同一条总线，所以宁可不缓存。
            return self::makeBus($driver, $resolved);
        }

        return self::$buses[$driver.'|'.$signature] ??= self::makeBus($driver, $resolved);
    }

    /**
     * 生效配置的稳定指纹；含对象/资源等无法稳定标识的值时返回 null（调用方据此放弃缓存）。
     *
     * 递归排序键：同一份配置换个写法（键序不同）必须落进同一个桶，
     * 否则又会退化成「两条总线、订阅互不可见」。
     *
     * @param array<array-key, mixed> $config
     */
    private static function fingerprint(array $config): ?string
    {
        ksort($config);
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $nested = self::fingerprint($value);
                if ($nested === null) {
                    return null;
                }
                $config[$key] = $nested;

                continue;
            }
            if (is_object($value) || is_resource($value)) {
                return null;
            }
        }

        return sha1(serialize($config));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function makeBus(string $driver, array $config): Bus
    {
        return match ($driver) {
            'redis' => new PubSub\RedisBus($config, self::logger()),
            'channel' => new PubSub\ChannelBus($config, self::logger()),
            default => new MemoryBus($config, self::logger()),
        };
    }

    /**
     * 丢弃已缓存的总线实例（订阅关系一并作废）。
     *
     * configure() 之后想换一套总线配置、或测试需要干净状态时调用；
     * 平时别调——正在跑的订阅者会静默失效。
     */
    public static function resetBuses(): void
    {
        self::$buses = [];
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
