<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\LongPolling;

use Throwable;

/**
 * Long-Polling 主题订阅 / 推送中心
 *
 * 业务场景：客户端 GET /wait?topic=orders 长连接等待；
 * 业务侧（控制器 / 队列消费者 / 定时任务）调用
 * Hub::push('orders', $payload) 立即唤醒所有等待中的连接。
 *
 * 用法（服务端）：
 *   // 1. 注册 hub 到 Server
 *   $server = new LongPolling\Server();
 *   $hub = LongPolling\Hub::singleton();
 *   $server->setHub($hub);
 *
 *   // 2. 业务代码中：
 *   LongPolling\Hub::singleton()->push('orders', ['new' => 123]);
 *
 *   // 3. 多实例共享（与 kode/process 配合）：
 *   $hub = new LongPolling\Hub(Hub::DRIVER_CHANNEL);
 *   $hub->attach('orders', function ($payload) { ... });
 */
final class Hub
{
    public const DRIVER_MEMORY = 'memory';
    public const DRIVER_CHANNEL = 'channel';   // 与 kode/process 协作
    public const DRIVER_REDIS = 'redis';     // 跨节点

    private static ?self $default = null;

    /** @var array<string, list<callable>> topic → 订阅回调列表 */
    private array $subscribers = [];

    private string $driver;

    /** @var null|callable push 实际执行器（跨进程 / 跨节点时由外部注入） */
    private $dispatcher = null;

    public function __construct(string $driver = self::DRIVER_MEMORY)
    {
        $this->driver = $driver;
    }

    public static function singleton(): self
    {
        return self::$default ??= new self();
    }

    public static function setSingleton(self $hub): void
    {
        self::$default = $hub;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * 注入跨进程分发器（由 kode/process 或 Redis 适配器实现）。
     */
    public function setDispatcher(callable $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * 订阅 topic 推送。
     */
    public function subscribe(string $topic, callable $callback): void
    {
        $this->subscribers[$topic][] = $callback;
    }

    /**
     * 取消订阅。
     */
    public function unsubscribe(string $topic, ?callable $callback = null): void
    {
        if (! isset($this->subscribers[$topic])) {
            return;
        }
        if ($callback === null) {
            unset($this->subscribers[$topic]);

            return;
        }
        $this->subscribers[$topic] = array_values(array_filter(
            $this->subscribers[$topic],
            fn($cb) => $cb !== $callback,
        ));
    }

    /**
     * 推送 payload 到 topic。
     */
    public function push(string $topic, mixed $payload): int
    {
        $count = 0;
        if (isset($this->subscribers[$topic])) {
            foreach ($this->subscribers[$topic] as $cb) {
                try {
                    $cb($payload);
                    $count++;
                } catch (Throwable) {
                    // 静默失败
                }
            }
        }
        // 跨进程 / 跨节点分发
        if ($this->dispatcher !== null) {
            try {
                ($this->dispatcher)($topic, $payload);
            } catch (Throwable) {
            }
        }

        return $count;
    }

    /**
     * 当前订阅数量。
     */
    public function topicCount(string $topic): int
    {
        return count($this->subscribers[$topic] ?? []);
    }

    /**
     * 全部已订阅 topic。
     *
     * @return list<string>
     */
    public function topics(): array
    {
        return array_keys($this->subscribers);
    }

    /**
     * 全部订阅数（用于监控）。
     */
    public function totalSubscribers(): int
    {
        $sum = 0;
        foreach ($this->subscribers as $list) {
            $sum += count($list);
        }

        return $sum;
    }
}
