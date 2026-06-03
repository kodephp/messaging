<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebSocket;

use Kode\Messaging\Adapter\WebSocket\Codec\Frame;
use Kode\Messaging\Adapter\WebSocket\Codec\OpCode;
use Kode\Messaging\Exception\WebSocketException;

/**
 * WebSocket 连接（服务端 / 客户端共用基类）
 */
class WebSocketConnection extends \Kode\Messaging\Connection\Connection
{
    /** @var resource */
    protected $stream;

    protected int $lastPongAt;

    public function __construct(
        string $connId,
        string $protocol,
        string $remoteAddress,
        $stream,
    ) {
        parent::__construct($connId, $protocol, $remoteAddress);
        $this->stream = $stream;
        $this->lastPongAt = time();
    }

    /**
     * 读取并解析一帧。
     */
    public function readFrame(bool $mustMask = false): ?Frame
    {
        $header = $this->readN(2);
        if ($header === '' || strlen($header) < 2) {
            return null;
        }
        $second = ord($header[1]);
        $len = $second & 0x7F;
        $offset = 2;
        if ($len === 126) {
            $ext = $this->readN(2);
            if (strlen($ext) < 2) {
                return null;
            }
            $len = unpack('n', $ext)[1];
            $offset += 2;
        } elseif ($len === 127) {
            $ext = $this->readN(8);
            if (strlen($ext) < 8) {
                return null;
            }
            $len = unpack('J', $ext)[1];
            $offset += 8;
        }
        $mask = '';
        $masked = ($second & 0x80) !== 0;
        if ($masked) {
            $mask = $this->readN(4);
            if (strlen($mask) < 4) {
                return null;
            }
            $offset += 4;
        }
        $payload = $this->readN($len);
        if (strlen($payload) < $len) {
            return null;
        }
        if ($masked) {
            $payload = $payload ^ str_repeat($mask, intdiv($len, 4) + 1);
            $payload = substr($payload, 0, $len);
        }
        $first = ord($header[0]);
        return new Frame(
            ($first & 0x80) !== 0,
            $first & 0x0F,
            $payload,
            $masked,
        );
    }

    private function readN(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = @fread($this->stream, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    public function send(mixed $payload, array $options = []): bool
    {
        if (!$this->open) {
            return false;
        }
        $opcode = isset($options['binary']) && $options['binary']
            ? OpCode::BINARY
            : OpCode::TEXT;
        $data = is_string($payload) ? $payload : json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $frame = new Frame(true, $opcode, $data);
        $bytes = $frame->encode(masked: $this->shouldMask());
        $written = @fwrite($this->stream, $bytes);
        if ($written === false || $written < strlen($bytes)) {
            $this->close(1011, 'write failed');
            return false;
        }
        return true;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!$this->open) {
            return;
        }
        try {
            $frame = Frame::close($code, $reason);
            @fwrite($this->stream, $frame->encode(masked: $this->shouldMask()));
        } catch (\Throwable) {
        }
        $this->open = false;
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        foreach ($this->closeCallbacks as $cb) {
            try {
                $cb($code === 1000 ? null : new \RuntimeException("close: {$code} {$reason}", $code));
            } catch (\Throwable) {
            }
        }
        $this->closeCallbacks = [];
    }

    public function sendPing(string $payload = ''): void
    {
        $frame = Frame::ping($payload);
        @fwrite($this->stream, $frame->encode(masked: $this->shouldMask()));
    }

    public function sendPong(string $payload = ''): void
    {
        $frame = Frame::pong($payload);
        @fwrite($this->stream, $frame->encode(masked: $this->shouldMask()));
    }

    public function markPong(): void
    {
        $this->lastPongAt = time();
    }

    public function lastPongAt(): int
    {
        return $this->lastPongAt;
    }

    /**
     * 尝试读取一帧；返回 null 表示连接已关闭或暂无数据。
     */
    public function readOnce(): ?Frame
    {
        return $this->readFrame();
    }

    /**
     * @return resource
     */
    public function stream()
    {
        return $this->stream;
    }

    /**
     * 是否需要在发送时 mask（客户端连服务端时为 true）。
     */
    protected function shouldMask(): bool
    {
        return false; // 由具体子类覆盖
    }
}
