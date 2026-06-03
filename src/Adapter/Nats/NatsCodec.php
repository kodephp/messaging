<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Nats;

use Kode\Messaging\Exception\NatsException;

/**
 * NATS 文本协议编解码
 *
 * NATS 协议由若干"操作"组成，每个操作以 \r\n 结束：
 *  - INFO   <json>
 *  - CONNECT <json>
 *  - PUB <subject> [reply-to] <#bytes>\r\n<payload>\r\n
 *  - HPUB <subject> [reply-to] <#bytes> <headers>\r\n<payload>\r\n  (NATS 2.2+)
 *  - SUB <subject> [queue group] <sid>
 *  - UNSUB <sid> [max-msgs]
 *  - MSG <subject> <sid> [reply-to] <#bytes>\r\n<payload>\r\n
 *  - HMSG <subject> <sid> [reply-to] <#bytes> <headers>\r\n<payload>\r\n
 *  - PING / PONG
 *  - +OK / -ERR <message>
 */
final class NatsCodec
{
    public const CRLF = "\r\n";

    /**
     * 编码 INFO（服务端 → 客户端）
     *
     * @param array<string, mixed> $info
     */
    public static function encodeInfo(array $info): string
    {
        return 'INFO ' . json_encode($info, JSON_UNESCAPED_SLASHES) . self::CRLF;
    }

    /**
     * 编码 CONNECT（客户端 → 服务端）
     *
     * @param array<string, mixed> $options
     */
    public static function encodeConnect(array $options = []): string
    {
        $payload = array_replace([
            'verbose'    => false,
            'pedantic'   => false,
            'name'       => 'kode-messaging',
            'lang'       => 'php',
            'version'    => '1.0',
            'protocol'   => 1,
        ], $options);
        return 'CONNECT ' . json_encode($payload, JSON_UNESCAPED_SLASHES) . self::CRLF;
    }

    /**
     * 编码 PUB（普通消息）
     */
    public static function encodePub(string $subject, string $payload, ?string $replyTo = null): string
    {
        $prefix = $replyTo !== null
            ? "PUB {$subject} {$replyTo} " . strlen($payload)
            : "PUB {$subject} " . strlen($payload);
        return $prefix . self::CRLF . $payload . self::CRLF;
    }

    /**
     * 编码 SUB（订阅）
     */
    public static function encodeSub(string $subject, int $sid, ?string $queueGroup = null): string
    {
        $line = $queueGroup !== null
            ? "SUB {$subject} {$queueGroup} {$sid}"
            : "SUB {$subject} {$sid}";
        return $line . self::CRLF;
    }

    /**
     * 编码 UNSUB（取消订阅）
     */
    public static function encodeUnsub(int $sid, ?int $maxMsgs = null): string
    {
        $line = $maxMsgs !== null
            ? "UNSUB {$sid} {$maxMsgs}"
            : "UNSUB {$sid}";
        return $line . self::CRLF;
    }

    /**
     * 编码 PING（心跳）
     */
    public static function encodePing(): string
    {
        return 'PING' . self::CRLF;
    }

    /**
     * 编码 PONG（心跳响应）
     */
    public static function encodePong(): string
    {
        return 'PONG' . self::CRLF;
    }

    /**
     * 编码 +OK 响应
     */
    public static function encodeOk(): string
    {
        return '+OK' . self::CRLF;
    }

    /**
     * 编码 -ERR 响应
     */
    public static function encodeErr(string $message): string
    {
        return '-ERR ' . trim($message) . self::CRLF;
    }

    /**
     * 编码 MSG（服务端 → 客户端）
     */
    public static function encodeMsg(string $subject, int $sid, string $payload, ?string $replyTo = null): string
    {
        $prefix = $replyTo !== null
            ? "MSG {$subject} {$sid} {$replyTo} " . strlen($payload)
            : "MSG {$subject} {$sid} " . strlen($payload);
        return $prefix . self::CRLF . $payload . self::CRLF;
    }

    /**
     * 解码一行控制命令（不含 PUB/MSG 的 payload 段）。
     *
     * @return array{op: string, args: list<string>, rest: string, payload: string}
     */
    public static function decodeCommand(string $line, string $buffer = ''): array
    {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            throw NatsException::invalidMessage('空命令行');
        }
        $parts = explode(' ', $line);
        $op = strtoupper(array_shift($parts));
        $args = $parts;

        return [
            'op'      => $op,
            'args'    => $args,
            'rest'    => $line,
            'payload' => '',
        ];
    }

    /**
     * 从缓冲区解析"控制行 + 可能的 payload"。
     *
     * PUB subject [reply] <#bytes>\r\n<payload>\r\n
     * MSG subject sid [reply] <#bytes>\r\n<payload>\r\n
     *
     * @return array{parsed: int, command: array<string, mixed>}|null
     */
    public static function parseWithPayload(string $buffer, int $offset = 0): ?array
    {
        $crlfPos = strpos($buffer, self::CRLF, $offset);
        if ($crlfPos === false) {
            return null;
        }
        $line = substr($buffer, $offset, $crlfPos - $offset);
        $line = rtrim($line, "\r\n");
        $parts = explode(' ', $line);
        if ($parts === []) {
            return null;
        }
        $op = strtoupper($parts[0]);
        if (!in_array($op, ['PUB', 'MSG', 'HPUB', 'HMSG'], true)) {
            return [
                'parsed' => $crlfPos + 2,
                'command' => ['op' => $op, 'args' => array_slice($parts, 1), 'payload' => ''],
            ];
        }

        // 解析参数，最后一个参数是 #bytes
        $args = array_slice($parts, 1);
        if ($args === []) {
            throw NatsException::invalidMessage('缺少参数: ' . $line);
        }
        $size = (int)array_pop($args);
        $payloadStart = $crlfPos + 2;
        $payloadEnd = $payloadStart + $size;
        if (strlen($buffer) < $payloadEnd) {
            return null; // 等更多数据
        }
        $payload = substr($buffer, $payloadStart, $size);
        // payload 之后还需要 CRLF
        if (substr($buffer, $payloadEnd, 2) !== self::CRLF) {
            throw NatsException::invalidMessage('payload 终止符缺失');
        }

        return [
            'parsed'  => $payloadEnd + 2,
            'command' => [
                'op'      => $op,
                'args'    => $args,
                'payload' => $payload,
                'size'    => $size,
            ],
        ];
    }
}
