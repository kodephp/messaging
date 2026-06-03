<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Stomp;

use Kode\Messaging\Exception\StompException;

/**
 * STOMP 文本协议帧编解码
 *
 * 帧格式：
 *   COMMAND\n
 *   header:value\n
 *   header:value\n
 *   \n
 *   body\x00
 *
 * 客户端命令：CONNECT / STOMP / SEND / SUBSCRIBE / UNSUBSCRIBE / ACK / NACK
 *             / BEGIN / COMMIT / ABORT / DISCONNECT
 * 服务端命令：CONNECTED / MESSAGE / RECEIPT / ERROR
 */
final class StompCodec
{
    public const NULL = "\x00";
    public const LF   = "\n";

    public const COMMAND_CONNECT      = 'CONNECT';
    public const COMMAND_STOMP        = 'STOMP';
    public const COMMAND_DISCONNECT   = 'DISCONNECT';
    public const COMMAND_SEND         = 'SEND';
    public const COMMAND_SUBSCRIBE    = 'SUBSCRIBE';
    public const COMMAND_UNSUBSCRIBE  = 'UNSUBSCRIBE';
    public const COMMAND_ACK          = 'ACK';
    public const COMMAND_NACK         = 'NACK';
    public const COMMAND_BEGIN        = 'BEGIN';
    public const COMMAND_COMMIT       = 'COMMIT';
    public const COMMAND_ABORT        = 'ABORT';
    public const COMMAND_CONNECTED    = 'CONNECTED';
    public const COMMAND_MESSAGE      = 'MESSAGE';
    public const COMMAND_RECEIPT      = 'RECEIPT';
    public const COMMAND_ERROR        = 'ERROR';

    /**
     * 编码一个 STOMP 帧。
     *
     * @param array<string, string> $headers
     */
    public static function encodeFrame(string $command, array $headers, string $body = ''): string
    {
        $buf = $command . self::LF;
        foreach ($headers as $k => $v) {
            // 头部值必须不含 \n 或 \r
            $v = strtr((string)$v, ["\n" => '', "\r" => '']);
            $buf .= "{$k}:{$v}" . self::LF;
        }
        $buf .= self::LF . $body . self::NULL;
        return $buf;
    }

    /**
     * 解码一个 STOMP 帧。
     *
     * @return array{command: string, headers: array<string, string>, body: string, consumed: int}|null
     */
    public static function decodeFrame(string $buffer, int $offset = 0): ?array
    {
        $nullPos = strpos($buffer, self::NULL, $offset);
        if ($nullPos === false) {
            return null;
        }
        $frameStr = substr($buffer, $offset, $nullPos - $offset);
        $consumed = $nullPos - $offset + 1;

        // 用双重换行（空行）分隔 headers 与 body
        $emptyLine = strpos($frameStr, self::LF . self::LF);
        if ($emptyLine === false) {
            throw StompException::parseFailed('帧缺少空行分隔', ['raw' => substr($frameStr, 0, 64)]);
        }
        $head = substr($frameStr, 0, $emptyLine);
        $body = substr($frameStr, $emptyLine + 2);

        $lines = explode(self::LF, $head);
        $command = trim((string)array_shift($lines));

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = substr($line, 0, $pos);
            $val = substr($line, $pos + 1);
            $headers[$key] = $val;
        }

        return [
            'command'  => $command,
            'headers'  => $headers,
            'body'     => $body,
            'consumed' => $consumed,
        ];
    }

    /**
     * 编码 CONNECT 帧。
     *
     * @param array<string, string> $headers
     */
    public static function encodeConnect(array $headers): string
    {
        $default = [
            'accept-version' => '1.0,1.1,1.2',
            'host'           => 'localhost',
            'heart-beat'     => '10000,10000',
        ];
        $headers = array_replace($default, $headers);
        return self::encodeFrame(self::COMMAND_CONNECT, $headers);
    }

    /**
     * 编码 STOMP 帧（与 CONNECT 类似，但接受更高版本）。
     *
     * @param array<string, string> $headers
     */
    public static function encodeStomp(array $headers): string
    {
        $default = [
            'accept-version' => '1.2',
            'host'           => 'localhost',
            'heart-beat'     => '10000,10000',
        ];
        $headers = array_replace($default, $headers);
        return self::encodeFrame(self::COMMAND_STOMP, $headers);
    }

    /**
     * 编码 SUBSCRIBE。
     *
     * @param array<string, string> $headers
     */
    public static function encodeSubscribe(string $destination, string $subscriptionId, array $headers = []): string
    {
        $headers = array_replace([
            'id'              => $subscriptionId,
            'destination'     => $destination,
            'ack'             => 'auto',
        ], $headers);
        return self::encodeFrame(self::COMMAND_SUBSCRIBE, $headers);
    }

    /**
     * 编码 SEND。
     *
     * @param array<string, string> $headers
     */
    public static function encodeSend(string $destination, string $body, array $headers = []): string
    {
        $headers = array_replace([
            'destination' => $destination,
            'content-length' => (string)strlen($body),
        ], $headers);
        return self::encodeFrame(self::COMMAND_SEND, $headers, $body);
    }

    /**
     * 编码 UNSUBSCRIBE。
     *
     * @param array<string, string> $headers
     */
    public static function encodeUnsubscribe(string $subscriptionId, array $headers = []): string
    {
        $headers = array_replace([
            'id' => $subscriptionId,
        ], $headers);
        return self::encodeFrame(self::COMMAND_UNSUBSCRIBE, $headers);
    }

    /**
     * 编码 ACK。
     *
     * @param array<string, string> $headers
     */
    public static function encodeAck(string $messageId, array $headers = []): string
    {
        $headers = array_replace(['id' => $messageId], $headers);
        return self::encodeFrame(self::COMMAND_ACK, $headers);
    }

    /**
     * 编码 DISCONNECT。
     *
     * @param array<string, string> $headers
     */
    public static function encodeDisconnect(array $headers = []): string
    {
        return self::encodeFrame(self::COMMAND_DISCONNECT, $headers);
    }

    /**
     * 编码 CONNECTED 响应。
     *
     * @param array<string, string> $headers
     */
    public static function encodeConnected(array $headers = []): string
    {
        $headers = array_replace([
            'version'    => '1.2',
            'session'    => 'kode-' . bin2hex(random_bytes(4)),
            'server'     => 'kode-messaging/1.0',
            'heart-beat' => '10000,10000',
        ], $headers);
        return self::encodeFrame(self::COMMAND_CONNECTED, $headers);
    }

    /**
     * 编码 MESSAGE 帧（服务端 → 订阅者）。
     *
     * @param array<string, string> $headers
     */
    public static function encodeMessage(string $destination, string $messageId, string $subscriptionId, string $body, array $headers = []): string
    {
        $headers = array_replace([
            'destination'   => $destination,
            'message-id'    => $messageId,
            'subscription'  => $subscriptionId,
            'content-length' => (string)strlen($body),
        ], $headers);
        return self::encodeFrame(self::COMMAND_MESSAGE, $headers, $body);
    }

    /**
     * 编码 ERROR 帧。
     *
     * @param array<string, string> $headers
     */
    public static function encodeError(string $message, array $headers = []): string
    {
        $headers['message'] = $message;
        return self::encodeFrame(self::COMMAND_ERROR, $headers, $message);
    }
}
