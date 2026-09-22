<?php

declare(strict_types=1);

namespace Kode\Messaging\PubSub;

use Kode\Messaging\Contract\BusInterface;
use Kode\Messaging\Support\IdGenerator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Pub/Sub 抽象基类
 *
 * 共享：主题匹配、订阅管理、payload 分发。
 */
abstract class Bus implements BusInterface
{
    /** @var array<string, array{id: string, topic: string, handler: callable, options: array<string, mixed>}> */
    protected array $subscribers = [];

    /** @var array<string, int> topic => 订阅者条数；0→1 注册底层、1→0 注销底层 */
    private array $topicRefs = [];

    /** @var array<string, string> 已编译的主题匹配正则缓存（按 pattern 维度） */
    private array $patternCache = [];

    public function __construct(
        protected array $config = [],
        protected LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * 订阅主题，返回订阅 ID（用于 unsubscribe）。
     *
     * 同一 topic 重复订阅只做「本地加人」：底层注册（redis channel 等）按 topic 引用计数，
     * 只在 0→1 时发生一次。否则订阅 N 次就会注册 N 个底层回调，一条消息分发 N 倍。
     * 由此带来的约束：底层注册用的是**首个**订阅者的 $options，后续同 topic 订阅者的
     * options 仅作用于本地记录。
     */
    public function subscribe(string $topic, callable $handler, array $options = []): string
    {
        $id = IdGenerator::next('sub');
        $this->subscribers[$id] = [
            'id' => $id,
            'topic' => $topic,
            'handler' => $handler,
            'options' => $options,
        ];

        $refs = ($this->topicRefs[$topic] ?? 0) + 1;
        $this->topicRefs[$topic] = $refs;
        if ($refs === 1) {
            $this->onSubscribe($topic, $options);
        }

        return $id;
    }

    /**
     * 取消订阅：最后一个订阅者走了才拆底层注册。
     *
     * 早先是「任一订阅者退订就 onUnsubscribe($topic)」，同 topic 的其他订阅者会被连带断供。
     */
    public function unsubscribe(string $subscriptionId): void
    {
        if (! isset($this->subscribers[$subscriptionId])) {
            return;
        }

        $topic = $this->subscribers[$subscriptionId]['topic'];
        unset($this->subscribers[$subscriptionId]);

        $refs = $this->topicRefs[$topic] ?? 0;
        if ($refs <= 1) {
            unset($this->topicRefs[$topic]);
            $this->onUnsubscribe($topic);

            return;
        }

        $this->topicRefs[$topic] = $refs - 1;
    }

    /**
     * 分发一条消息到匹配的订阅者。
     *
     * @param array<string, mixed> $payload
     */
    protected function dispatch(string $topic, array $payload): void
    {
        foreach ($this->subscribers as $sub) {
            if ($this->match($topic, $sub['topic'])) {
                try {
                    $ack = new SimpleAck();
                    ($sub['handler'])($payload, $ack);
                } catch (Throwable $e) {
                    $this->logger->error('pubsub handler error', [
                        'topic' => $topic,
                        'sub_id' => $sub['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * 主题匹配：支持 *（单级）/#（多级）。
     */
    public function match(string $topic, string $pattern): bool
    {
        if ($topic === $pattern) {
            return true;
        }
        $regex = $this->patternToRegex($pattern);

        return (bool) preg_match($regex, $topic);
    }

    /**
     * 编译主题模式为正则，并按 pattern 缓存结果。
     *
     * 原实现每次匹配都重新执行 preg_quote + str_replace，在
     * 「大量订阅者 × 高频发布」场景下，每个订阅者每次发布都会重复编译，
     * 产生可观的正则编译开销。按 pattern 维度缓存后，后续匹配仅执行 preg_match。
     */
    private function patternToRegex(string $pattern): string
    {
        if (! isset($this->patternCache[$pattern])) {
            $escaped = preg_quote($pattern, '#');
            $regex = str_replace(['\\*', '\\#'], ['[^/]+', '.*'], $escaped);
            $this->patternCache[$pattern] = '#^'.$regex.'$#';
        }

        return $this->patternCache[$pattern];
    }

    /**
     * 当前订阅者数量（可观测性）。
     */
    public function subscriberCount(): int
    {
        return count($this->subscribers);
    }

    /**
     * 当前订阅去重后的主题数量（可观测性）。
     */
    public function topicCount(): int
    {
        if ($this->subscribers === []) {
            return 0;
        }

        return count(array_unique(array_column($this->subscribers, 'topic')));
    }

    /**
     * 子类实现：实际订阅到外部系统（memory / redis / channel）。
     */
    abstract protected function onSubscribe(string $topic, array $options): void;

    /**
     * 子类实现：取消订阅。
     */
    abstract protected function onUnsubscribe(string $topic): void;
}
