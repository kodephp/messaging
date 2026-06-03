<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Sse;

use Kode\Messaging\Message\Message as Msg;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SSE 客户端连接（接收服务端推送）
 */
final class SseClientConnection extends SseConnection
{
    /** @var array<string, callable(Msg):void> */
    private array $handlers = [];

    public function __construct(
        string $connId,
        string $protocol,
        string $remoteAddress,
        $stream,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct($connId, $protocol, $remoteAddress, $stream);
    }

    public function onEvent(string $event, callable $handler): void
    {
        $this->handlers[$event] = $handler;
    }

    public function readLoop(): void
    {
        $buf = '';
        $event = null;
        $data = [];
        $id = null;
        while (!feof($this->stream)) {
            $line = @fgets($this->stream);
            if ($line === false) {
                break;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                // 派发一条消息
                if ($data !== []) {
                    $payload = implode("\n", $data);
                    $msg = Msg::fromRaw($payload, 'sse', event: $event, topic: null, headers: [], context: [
                        'connection_id' => $this->connId,
                        'remote_address' => $this->remoteAddress,
                    ]);
                    if ($event !== null && isset($this->handlers[$event])) {
                        try {
                            ($this->handlers[$event])($msg);
                        } catch (\Throwable $e) {
                            $this->logger->error('sse handler error', ['error' => $e->getMessage()]);
                        }
                    }
                }
                $event = null;
                $data = [];
                $id = null;
                continue;
            }
            if (str_starts_with($line, ':')) {
                continue; // 注释
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $field = substr($line, 0, $pos);
            $value = ltrim(substr($line, $pos + 1));
            switch ($field) {
                case 'event':
                    $event = $value;
                    break;
                case 'data':
                    $data[] = $value;
                    break;
                case 'id':
                    $id = $value;
                    break;
                case 'retry':
                    // 客户端不需处理
                    break;
            }
        }
    }
}
