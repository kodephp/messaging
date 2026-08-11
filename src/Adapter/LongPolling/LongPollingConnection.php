<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\LongPolling;

use Kode\Messaging\Connection\Connection;
use Throwable;

/**
 * Long-Polling 连接
 *
 * HTTP 长轮询中，一个客户端连接是一个挂起的 HTTP 请求。
 * 当服务端有数据时，把数据写入 HTTP 响应体并立即 flush。
 * 客户端收到响应后立即发起下一次长轮询请求。
 */
class LongPollingConnection extends Connection
{
    /** @var resource 底层 HTTP 响应 socket */
    private $socket;

    /** @var array<string, string> HTTP 响应头 */
    private array $headers;

    private int $statusCode = 200;

    private string $statusText = 'OK';

    private bool $responded = false;

    public function __construct(
        string $connId,
        string $remoteAddress,
        $socket,
        array $headers = [],
    ) {
        parent::__construct($connId, 'long-polling', $remoteAddress);
        $this->socket = $socket;
        $this->headers = array_replace([
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'close',
            'X-Connection-Id' => $connId,
        ], $headers);
    }

    public function send(mixed $payload, array $options = []): bool
    {
        if (! $this->open || $this->responded) {
            return false;
        }

        $data = $this->encode($payload, $options);
        $code = (int) ($options['status'] ?? $this->statusCode);
        $text = (string) ($options['status_text'] ?? $this->statusText);

        $headers = $this->headers;
        if (isset($options['headers']) && is_array($options['headers'])) {
            $headers = array_replace($headers, $options['headers']);
        }
        $headers['Content-Length'] = (string) strlen($data);

        $packet = "HTTP/1.1 {$code} {$text}\r\n";
        foreach ($headers as $k => $v) {
            $packet .= "{$k}: {$v}\r\n";
        }
        $packet .= "\r\n{$data}";

        $bytes = @fwrite($this->socket, $packet);
        if ($bytes === false) {
            $this->close(0, 'response write failed');

            return false;
        }
        $this->responded = true;

        return true;
    }

    /**
     * 是否已发送响应。
     */
    public function hasResponded(): bool
    {
        return $this->responded;
    }

    /**
     * 强制关闭 socket（不发送响应体）。
     */
    public function terminate(): void
    {
        $this->open = false;
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        foreach ($this->closeCallbacks as $cb) {
            try {
                $cb(null);
            } catch (Throwable) {
            }
        }
        $this->closeCallbacks = [];
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->responded) {
            $this->terminate();

            return;
        }

        // 未响应就关闭，发送空响应
        try {
            $this->send('', ['status' => 204, 'status_text' => 'No Content']);
        } catch (Throwable) {
            $this->terminate();
        }
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * 把业务载荷编码为响应体字符串。
     */
    private function encode(mixed $payload, array $options): string
    {
        if (is_string($payload)) {
            return $payload;
        }
        if (is_array($payload) || is_object($payload)) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if (! empty($options['throw_on_error'])) {
                $flags |= JSON_THROW_ON_ERROR;
            }

            return (string) json_encode($payload, $flags);
        }
        if ($payload === null) {
            return '';
        }

        return (string) $payload;
    }
}
