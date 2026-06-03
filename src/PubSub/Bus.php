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

    private function patternToRegex(string $pattern): string
    {
        $escaped = preg_quote($pattern, '#');
        $regex = str_replace(['\\*', '\\#'], ['[^/]+', '.*'], $escaped);
        return '#^' . $regex . '$#';
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
