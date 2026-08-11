<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebTransport;

use Kode\Messaging\Exception\WebTransportException;

/**
 * WebTransport 会话 / 流 / Datagram 编解码
 *
 * 真实 WebTransport 基于 HTTP/3 (QUIC)，本 Codec 主要承担：
 *  - 描述 WebTransport 帧语义（流类型 / Datagram 标签）
 *  - 提供与底层 QUIC 端点的握手请求构造（CONNECT-UDP 思路）
 *
> 注：完整 HTTP/3 + QUIC 实现需 aioquic / msquic / curl-impersonate，
> 本适配器通常作为"业务接口"使用，由具体后端（PHP-FPM 调外部 QUIC 进程）
> 提供 HTTP/3 终结，本 Codec 负责把 WebTransport 业务流映射到后端抽象。
 */
final class WebTransportCodec
{
    /** WebTransport over HTTP/2 实验头（fallback） */
    public const ENABLE_WT_OVER_H2 = 'webtransport-enabled';
    public const SUBPROTOCOL_HEADER = 'wt-subprotocol';

    /**
     * 编码一个 CONNECT 请求头（WebTransport 握手 / HTTP/2 fallback）。
     *
     * @param array<string, string> $headers
     */
    public static function encodeConnectRequest(string $path, array $headers = []): string
    {
        $lines = [
            "CONNECT {$path} HTTP/1.1",
            'Host: '.($headers['host'] ?? 'localhost'),
        ];
        $lines[] = self::ENABLE_WT_OVER_H2.': 1';
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            if (in_array($lk, ['host', 'connection'], true)) {
                continue;
            }
            $lines[] = "{$k}: {$v}";
        }

        return implode("\r\n", $lines)."\r\n\r\n";
    }

    /**
     * 解码 CONNECT 响应状态行与头部。
     *
     * @return null|array{status: int, reason: string, headers: array<string, string>}
     */
    public static function decodeConnectResponse(string $raw): ?array
    {
        $headerEnd = strpos($raw, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }
        $headerPart = substr($raw, 0, $headerEnd);
        $lines = explode("\r\n", $headerPart);
        if ($lines === [] || ! preg_match('#^HTTP/[\d.]+\s+(\d+)(?:\s+(.*))?$#', $lines[0], $m)) {
            throw WebTransportException::handshakeFailed('无效的 HTTP 响应');
        }
        $headers = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $pos)));
            $val = trim(substr($line, $pos + 1));
            $headers[$key] = $val;
        }

        return [
            'status' => (int) $m[1],
            'reason' => $m[2] ?? '',
            'headers' => $headers,
        ];
    }

    /**
     * 构造一个 Datagram 标签（WebTransport 0x00 / 0x01 区分可靠/不可靠）。
     */
    public static function encodeDatagram(string $payload, bool $reliable = false): string
    {
        return ($reliable ? "\x01" : "\x00").$payload;
    }

    /**
     * 解析一个 Datagram。
     *
     * @return null|array{reliable: bool, payload: string}
     */
    public static function decodeDatagram(string $datagram): ?array
    {
        if ($datagram === '') {
            return null;
        }
        $tag = ord($datagram[0]);

        return [
            'reliable' => ($tag & 0x01) !== 0,
            'payload' => substr($datagram, 1),
        ];
    }
}
