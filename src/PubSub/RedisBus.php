<?php

declare(strict_types=1);

namespace Kode\Messaging\PubSub;

use Kode\Messaging\Exception\MessagingException;
use Redis;

/**
 * 基于 Redis 的跨节点 Pub/Sub 总线
 *
 * 依赖：ext-redis 或 phpredis（不强制硬依赖，未安装时由调用方注入 Redis 实例）。
 *
 * 典型用法：
 *   $bus = new RedisBus(['host' => '127.0.0.1', 'port' => 6379]);
 *   $bus->subscribe('orders:created', function ($p) { ... });
 *   $bus->publish('orders:created', ['id' => 1001]);
 *
 * 注意：Redis Pub/Sub 是"最多一次"语义，消息不持久化。
 * 如需 QoS 1/2，请改用 Redis Streams（kode/queue 也可对接）。
 */
final class RedisBus extends Bus
{
    /** @var null|\Predis\Client|Redis */
    private $redis = null;

    /** @var array<int, callable> */
    private array $loops = [];

    public function __construct(array $config = [], \Psr\Log\LoggerInterface $logger = new \Psr\Log\NullLogger())
    {
        parent::__construct($config, $logger);
        $this->redis = $this->createRedisClient();
    }

    public function driver(): string
    {
        return 'redis';
    }

    public function publish(string $topic, array $payload, array $options = []): void
    {
        $channel = $this->config['prefix'].$topic;
        $message = json_encode(
            ['topic' => $topic, 'payload' => $payload, 'time' => microtime(true)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $this->redis->publish($channel, $message);
    }

    protected function onSubscribe(string $topic, array $options): void
    {
        $channel = $this->config['prefix'].$topic;
        // 每个总线实例一个 loop
        $this->loops[] = function () use ($channel, $topic): void {
            $this->redis->subscribe([$channel], function (Redis $r, string $chan, string $msg) use ($topic): void {
                $decoded = json_decode($msg, true);
                if (is_array($decoded) && isset($decoded['payload']) && is_array($decoded['payload'])) {
                    $this->dispatch($decoded['topic'] ?? $topic, $decoded['payload']);
                }
            });
        };
    }

    protected function onUnsubscribe(string $topic): void
    {
        // Redis subscribe 在 close 时自动清理
    }

    /**
     * 启动订阅循环（阻塞；如需非阻塞，使用 setOption 配合 setOption(Redis::OPT_READ_TIMEOUT, ...)）。
     */
    public function loop(): void
    {
        foreach ($this->loops as $cb) {
            $cb();
        }
    }

    private function createRedisClient(): Redis|\Predis\Client
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? 6379);
        $db = (int) ($this->config['db'] ?? 0);

        if (class_exists(Redis::class) && extension_loaded('redis')) {
            $r = new Redis();
            $r->connect($host, $port, 2.0);
            if ($db > 0) {
                $r->select($db);
            }

            return $r;
        }
        if (class_exists(\Predis\Client::class)) {
            return new \Predis\Client([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
                'database' => $db,
            ]);
        }

        throw new MessagingException(
            'RedisBus 需要 ext-redis 或 predis/predis 扩展',
            5008,
        );
    }
}
