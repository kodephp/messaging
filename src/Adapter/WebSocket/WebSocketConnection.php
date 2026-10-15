<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebSocket;

use Kode\Messaging\Adapter\WebSocket\Codec\Frame;
use Kode\Messaging\Adapter\WebSocket\Codec\OpCode;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * WebSocket 连接（服务端 / 客户端共用基类）
 */
class WebSocketConnection extends \Kode\Messaging\Connection\Connection
{
    /** @var resource */
    protected $stream;

    protected int $lastPongAt;

    protected LoggerInterface $logger;

    /** 入站帧载荷上限（字节）：在分配内存之前拒绝超大帧，防内存耗尽 DoS */
    protected int $maxFrameSize;

    /** 单帧总读取超时（秒）：防止慢速滴灌（slowloris）帧把事件循环拖死 */
    protected float $readTimeout;
    /**
     * @param resource $stream
     */
    public function __construct(
        string $connId,
        string $protocol,
        string $remoteAddress,
        $stream,
        ?LoggerInterface $logger = null,
        int $maxFrameSize = 1_048_576,
        float $readTimeout = 10.0,
    ) {
        parent::__construct($connId, $protocol, $remoteAddress);
        $this->stream = $stream;
        $this->lastPongAt = time();
        $this->logger = $logger ?? new NullLogger();
        $this->maxFrameSize = max(1, $maxFrameSize);
        $this->readTimeout = $readTimeout > 0 ? $readTimeout : 10.0;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * 读取并解析一帧（流式）。
     *
     * 只负责「拉取多少字节」（带单帧总超时与分配前上限守卫），语义校验
     * （掩码方向、控制帧规则、64 位长度最高位、保留 opcode）全部委托给
     * Codec\Frame::decode()——与解码层共用同一套 RFC 6455 防线，避免
     * 两套解析逻辑各自漂移。
     *
     * 违反协议时按 RFC 关闭连接并返回 null；对端正常 EOF 则直接返回
     * null（由调用方按 eof 元数据回收连接）。
     *
     * @param bool $mustMask true=要求对端帧已 mask（服务端收客户端帧）；
     *                       false=要求未 mask（客户端收服务端帧）
     */
    public function readFrame(bool $mustMask = false): ?Frame
    {
        $deadline = microtime(true) + $this->readTimeout;

        $header = $this->readN(2, $deadline);
        if (strlen($header) < 2) {
            return $this->abortShortRead();
        }

        $second = ord($header[1]);
        $len = $second & 0x7F;
        $ext = '';
        if ($len === 126) {
            $ext = $this->readN(2, $deadline);
            if (strlen($ext) < 2) {
                return $this->abortShortRead();
            }
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->readN(8, $deadline);
            if (strlen($ext) < 8) {
                return $this->abortShortRead();
            }
            $len = unpack('J', $ext)[1];
            if ($len < 0) {
                // 64 位长度最高位必须为 0（RFC 6455 §5.2）
                return $this->failProtocol(\Kode\Messaging\Exception\WebSocketException::CODE_PROTOCOL_ERROR, '64-bit length invalid');
            }
        }

        // 分配前上限守卫：绝不能按对端声明的长度去 readN 分配内存
        if ($len > $this->maxFrameSize) {
            return $this->failProtocol(\Kode\Messaging\Exception\WebSocketException::CODE_MESSAGE_TOO_BIG, 'message too big');
        }

        $mask = '';
        if (($second & 0x80) !== 0) {
            $mask = $this->readN(4, $deadline);
            if (strlen($mask) < 4) {
                return $this->abortShortRead();
            }
        }

        $payload = $this->readN($len, $deadline);
        if (strlen($payload) < $len) {
            return $this->abortShortRead();
        }

        try {
            return Frame::decode($header . $ext . $mask . $payload, mustMask: $mustMask, maxPayload: $this->maxFrameSize);
        } catch (\Kode\Messaging\Exception\WebSocketException $e) {
            $code = $e->getCode();
            if ($code < 1000 || $code > 1015) {
                $code = \Kode\Messaging\Exception\WebSocketException::CODE_PROTOCOL_ERROR;
            }
            return $this->failProtocol($code, substr($e->getMessage(), 0, 100));
        }
    }

    /**
     * 读取不足且对端已 EOF：正常断开，返回 null 由调用方回收。
     * 读取不足但流仍存活（帧超时/读错误）：以 1001 关闭，防慢速滴灌占住事件循环。
     */
    private function abortShortRead(): ?Frame
    {
        if (is_resource($this->stream) && ! feof($this->stream)) {
            $this->close(\Kode\Messaging\Exception\WebSocketException::CODE_GOING_AWAY, 'frame read timeout');
        }

        return null;
    }

    /** 协议违规：按 code 关闭连接并返回 null */
    private function failProtocol(int $code, string $reason): ?Frame
    {
        $this->close($code, $reason);

        return null;
    }

    /**
     * 读取恰好 $n 字节；$deadline（单调秒级时间戳）到达或流结束即短返回。
     */
    private function readN(int $n, ?float $deadline = null): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if ($deadline !== null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                // 把底层流超时钉在剩余时间内：否则阻塞的 fread 会按
                // 流自身的超时（可能长达默认 60s）穿透帧级 deadline。
                $sec = (int) $remaining;
                stream_set_timeout($this->stream, $sec, (int) (($remaining - $sec) * 1_000_000));
            }
            $chunk = @fread($this->stream, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    #[\Override]
    public function send(mixed $payload, array $options = []): bool
    {
        if (! $this->open) {
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

    #[\Override]
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (! $this->open) {
            return;
        }

        try {
            $frame = Frame::close($code, $reason);
            @fwrite($this->stream, $frame->encode(masked: $this->shouldMask()));
        } catch (Throwable) {
        }
        $this->open = false;
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        foreach ($this->closeCallbacks as $cb) {
            try {
                $cb($code === 1000 ? null : new RuntimeException("close: {$code} {$reason}", $code));
            } catch (Throwable) {
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
     * 尝试读取一帧；返回 null 表示连接已关闭、暂无数据或协议违规已断开。
     *
     * @param bool $mustMask 见 readFrame()；服务端应传 true（要求客户端帧已 mask）
     */
    public function readOnce(bool $mustMask = false): ?Frame
    {
        return $this->readFrame($mustMask);
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
