<?php

declare(strict_types=1);

namespace Kode\Messaging\PubSub;

use Kode\Messaging\Exception\MessagingException;

/**
 * 基于 kode/process\Channel 的多进程 Pub/Sub 总线
 *
 * 适用：单节点多 Worker 进程（kode/process）。
 * 性能：极快（共享内存 / Unix Socket）。
 */
final class ChannelBus extends Bus
{
    public function __construct(
        array $config = [],
        \Psr\Log\LoggerInterface $logger = new \Psr\Log\NullLogger(),
    ) {
        parent::__construct($config, $logger);
        if (! class_exists(\Kode\Process\Channel\Client::class)) {
            throw new MessagingException(
                'ChannelBus 需要 kode/process 包',
                5009,
            );
        }
    }

    public function driver(): string
    {
        return 'channel';
    }

    public function publish(string $topic, array $payload, array $options = []): void
    {
        $client = \Kode\Process\Channel\Client::instance();
        $client->publish($topic, $payload);
    }

    protected function onSubscribe(string $topic, array $options): void
    {
        $client = \Kode\Process\Channel\Client::instance();
        $client->on($topic, function (mixed $payload) use ($topic): void {
            if (is_array($payload)) {
                $this->dispatch($topic, $payload);
            }
        });
    }

    protected function onUnsubscribe(string $topic): void
    {
        // Channel Client 不支持单 topic 注销，整个进程退出时清理
    }
}
