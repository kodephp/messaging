<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Sse;

use Kode\Messaging\Connection\Connection;
use RuntimeException;
use Throwable;

/**
 * SSE 连接
 */
class SseConnection extends Connection
{
    /** @var resource */
    protected $stream;

    public function __construct(
        string $connId,
        string $protocol,
        string $remoteAddress,
        $stream,
    ) {
        parent::__construct($connId, $protocol, $remoteAddress);
        $this->stream = $stream;
    }

    public function send(mixed $payload, array $options = []): bool
    {
        if (! $this->open) {
            return false;
        }
        $message = \Kode\Messaging\Message\Message::of(
            $payload,
            'sse',
            event: $options['event'] ?? null,
        );
        $text = Formatter::fromMessage($message);
        $bytes = @fwrite($this->stream, $text);
        if ($bytes === false) {
            $this->close(1000, 'write failed');

            return false;
        }
        @fflush($this->stream);

        return true;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if (! $this->open) {
            return;
        }
        $this->open = false;
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        foreach ($this->closeCallbacks as $cb) {
            try {
                $cb($code === 1000 ? null : new RuntimeException("close: {$reason}", $code));
            } catch (Throwable) {
            }
        }
        $this->closeCallbacks = [];
    }

    public function stream()
    {
        return $this->stream;
    }
}
