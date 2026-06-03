<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Nats;

use Kode\Messaging\Adapter\WebSocket\WebSocketConnection;

/**
 * NATS 客户端连接
 *
 * 与 MqttConnection 思路一致：复用 Connection 通用能力，
 * 扩展"按 subject 匹配"和"按 sid 追踪订阅"。
 */
class NatsConnection extends WebSocketConnection
{
    /** @var array<string, callable(string $subject, string $payload, \Kode\Messaging\Message\Message $message): void> */
    protected array $subjectHandlers = [];

    /** @var array<int, callable(string $reply, string $payload): void> */
    protected array $replyHandlers = [];

    private int $nextSid = 1;

    public function addSubjectHandler(string $subjectFilter, callable $handler): void
    {
        $this->subjectHandlers[$subjectFilter] = $handler;
    }

    public function onReply(string $inbox, callable $handler): void
    {
        $this->replyHandlers[$inbox] = $handler;
    }

    public function dispatchMessage(string $subject, string $payload, ?string $replyTo, int $sid): void
    {
        $msg = \Kode\Messaging\Message\Message::of(
            $payload,
            'nats',
            topic: $subject,
            context: [
                'connection_id'  => $this->connId,
                'remote_address' => $this->remoteAddress,
                'sid'            => $sid,
                'reply_to'       => $replyTo,
            ],
        );
        foreach ($this->subjectHandlers as $filter => $handler) {
            if ($this->matchSubject($filter, $subject)) {
                try {
                    $handler($subject, $payload, $msg);
                } catch (\Throwable) {
                }
            }
        }
        if ($replyTo !== null && isset($this->replyHandlers[$replyTo])) {
            try {
                ($this->replyHandlers[$replyTo])($replyTo, $payload);
            } catch (\Throwable) {
            }
        }
    }

    public function nextSid(): int
    {
        $sid = $this->nextSid++;
        if ($this->nextSid > 0xFFFF) {
            $this->nextSid = 1;
        }
        return $sid;
    }

    /**
     * NATS subject 匹配：* 匹配单个 token，> 匹配尾部多 token。
     */
    private function matchSubject(string $filter, string $subject): bool
    {
        if ($filter === $subject) {
            return true;
        }
        if ($filter === '>') {
            return true;
        }
        $f = explode('.', $filter);
        $s = explode('.', $subject);
        $i = 0;
        while ($i < count($f) && $i < count($s)) {
            if ($f[$i] === '>') {
                return true;
            }
            if ($f[$i] === '*') {
                $i++;
                continue;
            }
            if ($f[$i] !== $s[$i]) {
                return false;
            }
            $i++;
        }
        return count($f) === count($s);
    }
}
