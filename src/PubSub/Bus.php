<?php

declare(strict_types=1);

namespace Kode\Messaging\PubSub;

use Kode\Messaging\Contract\AcknowledgeInterface;
use Kode\Messaging\Contract\BusInterface;
use Kode\Messaging\Support\IdGenerator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pub/Sub 抽象基类
 *
 * 共享：主题匹配、订阅管理、payload 分发。
 */
abstract class Bus implements BusInterface
{
    /** @var array<string, array{id: string, topic: string, handler: callable, options: array<string, mixed>}> */
    protected array $subscribers = [];

    /** @var array<string, string> 已编译的主题匹配正则缓存（按 pattern 维度） */
    private array $patternCache = [];

    public function __construct(
        protected array $config = [],
        protected LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function subscribe(string $topic, callable $handler, array $options = []): string
    {
        $id = IdGenerator::next('sub');
        $this->subscribers[$id] = [
            'id'      => $id,
            'topic'   => $topic,
            'handler' => $handler,
            'options' => $options,
        ];
        $this->onSubscribe($topic, $options);
        return $id;
    }

    public function unsubscribe(string $subscriptionId): void
    {
        if (isset($this->subscribers[$subscriptionId])) {
            $topic = $this->subscribers[$subscriptionId]['topic'];
            unset($this->subscribers[$subscriptionId]);
            $this->onUnsubscribe($topic);
        }
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
                } catch (\Throwable $e) {
                    $this->logger->error('pubsub handler error', [
                        'topic'  => $topic,
                        'sub_id' => $sub['id'],
                        'error'  => $e->getMessage(),
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
        return (bool)preg_match($regex, $topic);
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
        if (!isset($this->patternCache[$pattern])) {
            $escaped = preg_quote($pattern, '#');
            $regex = str_replace(['\\*', '\\#'], ['[^/]+', '.*'], $escaped);
            $this->patternCache[$pattern] = '#^' . $regex . '$#';
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
