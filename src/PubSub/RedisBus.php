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

    /** @var array<string, string> topic => 底层 redis channel（底层订阅由 loop() 一次性建立） */
    private array $channels = [];

    /** @var bool loop() 是否正卡在阻塞式 subscribe 里 */
    private bool $subscribing = false;

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
        $channel = $this->channel($topic);
        $message = json_encode(
            ['topic' => $topic, 'payload' => $payload, 'time' => microtime(true)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $this->redis->publish($channel, $message);
    }

    /**
     * 只登记映射：真正的底层订阅由 loop() 用一次 subscribe 覆盖全部 channel。
     *
     * 旧实现给每个订阅都塞一个「阻塞式 subscribe(单 channel)」闭包，loop() 顺序遍历时
     * 第一个闭包就把循环卡死，第二个及之后的 topic 永远收不到消息。
     */
    protected function onSubscribe(string $topic, array $options): void
    {
        $this->channels[$topic] = $this->channel($topic);
    }

    /**
     * 注销 topic（基类按引用计数，最后一个订阅者走了才会走到这里）。
     *
     * 阻塞循环内无法即时感知集合变化，故只在客户端支持 unsubscribe 且正在订阅时尝试退出，
     * 其余情况等本轮 loop() 返回后按最新集合重订。
     */
    protected function onUnsubscribe(string $topic): void
    {
        $channel = $this->channels[$topic] ?? null;
        unset($this->channels[$topic]);

        if ($channel !== null && $this->subscribing && method_exists($this->redis, 'unsubscribe')) {
            /* @var \Redis $this->redis */
            $this->redis->unsubscribe($channel);
        }
    }

    /** 已登记的 topic => channel 映射（可观测性 / 测试）。 */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * 启动订阅循环（阻塞；如需非阻塞，使用 setOption 配合 setOption(Redis::OPT_READ_TIMEOUT, ...)）。
     *
     * @param int<1, max> $rounds 订阅被服务端断开后最多重订几轮（默认 1 轮，即只进一次）
     */
    public function loop(int $rounds = 1): void
    {
        for ($i = 0; $i < $rounds; ++$i) {
            // 每轮重新取快照：上一轮期间的 subscribe/unsubscribe 在这里生效
            $channels = array_values($this->channels);
            if ($channels === []) {
                return;
            }

            $byChannel = array_flip($this->channels);
            $this->subscribing = true;

            try {
                $this->redis->subscribe($channels, function ($redis, ?string $chan, ?string $msg) use ($byChannel): void {
                    $decoded = json_decode((string) $msg, true);
                    if (! is_array($decoded) || ! isset($decoded['payload']) || ! is_array($decoded['payload'])) {
                        return;
                    }

                    $topic = $decoded['topic'] ?? $byChannel[(string) $chan] ?? null;
                    if (is_string($topic)) {
                        $this->dispatch($topic, $decoded['payload']);
                    }
                });
            } finally {
                $this->subscribing = false;
            }
        }
    }

    /** 底层 channel 名：prefix 未配置时取空串（旧实现直接读 $this->config['prefix']，触发 undefined key 告警）。 */
    private function channel(string $topic): string
    {
        return (string) ($this->config['prefix'] ?? '').$topic;
    }

    private function createRedisClient(): Redis|\Predis\Client
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int) ($this->config['port'] ?? 6379);
        $db = (int) ($this->config['db'] ?? 0);
        $password = $this->config['password'] ?? null;
        $timeout = (float) ($this->config['timeout'] ?? 2.0);

        if (class_exists(Redis::class) && extension_loaded('redis')) {
            $r = new Redis();
            $r->connect($host, $port, $timeout);
            if (is_string($password) && $password !== '') {
                $r->auth($password);
            }
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
                'password' => is_string($password) && $password !== '' ? $password : null,
            ]);
        }

        throw new MessagingException(
            'RedisBus 需要 ext-redis 或 predis/predis 扩展',
            5008,
        );
    }
}
