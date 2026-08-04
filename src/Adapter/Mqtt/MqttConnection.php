<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt;

use Kode\Messaging\Adapter\WebSocket\WebSocketConnection;
use Kode\Messaging\Message\Message as Msg;
use Kode\Messaging\Support\TopicMatcher;

/**
 * MQTT 客户端连接
 */
class MqttConnection extends WebSocketConnection
{
    /** @var array<string, callable(string $topic, string $payload, Msg $message): void> */
    protected array $topicHandlers = [];

    /** @var array<int, callable(array): void> */
    protected array $ackHandlers = [];

    /** @var callable|null */
    protected $onConnect = null;

    public function setOnConnect(?callable $cb): void
    {
        $this->onConnect = $cb;
    }

    public function addTopicHandler(string $topicFilter, callable $handler): void
    {
        $this->topicHandlers[$topicFilter] = $handler;
    }

    public function onAck(int $packetId, callable $handler): void
    {
        $this->ackHandlers[$packetId] = $handler;
    }

    public function dispatchPublish(string $topic, string $payload, int $qos, bool $retain, int $packetId): void
    {
        foreach ($this->topicHandlers as $filter => $handler) {
            if ($this->match($filter, $topic)) {
                $msg = Msg::of(
                    $payload,
                    'mqtt',
                    topic: $topic,
                    qos: $qos,
                    retain: $retain,
                    context: [
                        'connection_id' => $this->connId,
                        'remote_address' => $this->remoteAddress,
                        'packet_id'      => $packetId,
                    ],
                );
                try {
                    $handler($topic, $payload, $msg);
                } catch (\Throwable) {
                }
            }
        }
    }

    public function dispatchAck(int $packetId, array $info): void
    {
        $handler = $this->ackHandlers[$packetId] ?? null;
        if ($handler !== null) {
            try {
                $handler($info);
            } catch (\Throwable) {
            }
            unset($this->ackHandlers[$packetId]);
        }
    }

    public function callOnConnect(): void
    {
        if ($this->onConnect !== null) {
            try {
                ($this->onConnect)($this);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * 主题过滤器匹配（MQTT 标准语义）。
     *
     * 修复：原实现把 `*` 当作单级通配符（`preg_quote` 会转义 `+`），
     * 导致以 MQTT 标准过滤器 `a/+/b` 订阅时，客户端本地回调永远匹配不上，
     * 消息虽从 Broker 收到却不会触发 handler。现统一走 {@see TopicMatcher}。
     */
    private function match(string $filter, string $topic): bool
    {
        return TopicMatcher::matches($filter, $topic);
    }
}
