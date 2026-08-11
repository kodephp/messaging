<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\WebSocket\Codec;

use Kode\Messaging\Exception\WebSocketException;

/**
 * WebSocket 握手（RFC 6455 §4）
 *
 * 客户端构造握手请求，服务端验证升级请求。
 */
final class Handshake
{
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * 构造客户端握手请求。
     */
    public static function clientRequest(
        string $host,
        string $path = '/',
        ?string $origin = null,
        ?string $subprotocol = null,
        array $headers = [],
    ): string {
        $key = base64_encode(random_bytes(16));
        $req = "GET {$path} HTTP/1.1\r\n";
        $req .= "Host: {$host}\r\n";
        $req .= "Upgrade: websocket\r\n";
        $req .= "Connection: Upgrade\r\n";
        $req .= "Sec-WebSocket-Key: {$key}\r\n";
        $req .= "Sec-WebSocket-Version: 13\r\n";
        if ($origin !== null) {
            $req .= "Origin: {$origin}\r\n";
        }
        if ($subprotocol !== null) {
            $req .= "Sec-WebSocket-Protocol: {$subprotocol}\r\n";
        }
        foreach ($headers as $k => $v) {
            $req .= "{$k}: {$v}\r\n";
        }
        $req .= "\r\n";

        return $req;
    }

    /**
     * 计算 Sec-WebSocket-Accept。
     */
    public static function acceptKey(string $clientKey): string
    {
        return base64_encode(sha1($clientKey.self::GUID, true));
    }

    /**
     * 校验客户端握手请求，返回 Sec-WebSocket-Accept 与选中的 subprotocol。
     *
     * @param string $rawRequest HTTP 请求原文
     * @param array<string, mixed> $config
     * @return array{accept: string, subprotocol: ?string, host: string, path: string, origin: ?string}
     */
    public static function verifyServer(
        string $rawRequest,
        array $config = [],
    ): array {
        $lines = explode("\r\n", $rawRequest);
        $requestLine = array_shift($lines) ?? '';
        if (! preg_match('#^HTTP/1\.[01]\s+101#', $requestLine)) {
            throw WebSocketException::handshakeFailed('无效的状态行', ['line' => $requestLine]);
        }

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                break;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $k = strtolower(trim(substr($line, 0, $pos)));
                $v = trim(substr($line, $pos + 1));
                $headers[$k] = $v;
            }
        }

        if (($headers['upgrade'] ?? '') !== 'websocket') {
            throw WebSocketException::handshakeFailed('Upgrade 头缺失');
        }
        if (! preg_match('/\bUpgrade\b/i', $headers['connection'] ?? '')) {
            throw WebSocketException::handshakeFailed('Connection 头缺失 Upgrade');
        }
        if (! isset($headers['sec-websocket-accept'])) {
            throw WebSocketException::handshakeFailed('Sec-WebSocket-Accept 缺失');
        }
        if (! isset($config['expected_accept']) || $headers['sec-websocket-accept'] !== $config['expected_accept']) {
            throw WebSocketException::handshakeFailed('Sec-WebSocket-Accept 不匹配', [
                'expected' => $config['expected_accept'] ?? null,
                'got' => $headers['sec-websocket-accept'],
            ]);
        }

        return [
            'accept' => $headers['sec-websocket-accept'],
            'subprotocol' => $headers['sec-websocket-protocol'] ?? null,
            'host' => $headers['host'] ?? '',
            'path' => '',
            'origin' => $headers['origin'] ?? null,
        ];
    }

    /**
     * 校验客户端握手并构造服务端响应。
     *
     * @param array<string, mixed> $config
     * @return string 服务端响应原文
     */
    public static function serverResponse(string $rawRequest, array $config = []): string
    {
        $lines = explode("\r\n", $rawRequest);
        $requestLine = array_shift($lines) ?? '';
        if (! preg_match('#^GET\s+(\S+)\s+HTTP/1\.[01]#', $requestLine, $m)) {
            throw WebSocketException::handshakeFailed('无效的请求行', ['line' => $requestLine]);
        }
        $path = $m[1];

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                break;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $k = strtolower(trim(substr($line, 0, $pos)));
                $v = trim(substr($line, $pos + 1));
                $headers[$k] = $v;
            }
        }

        // 必填头校验
        if (($headers['upgrade'] ?? '') !== 'websocket') {
            throw WebSocketException::handshakeFailed('Upgrade 头必须为 websocket');
        }
        if (! preg_match('/\bUpgrade\b/i', $headers['connection'] ?? '')) {
            throw WebSocketException::handshakeFailed('Connection 头必须包含 Upgrade');
        }
        if (! isset($headers['sec-websocket-key'])) {
            throw WebSocketException::handshakeFailed('Sec-WebSocket-Key 缺失');
        }
        if (($headers['sec-websocket-version'] ?? '') !== '13') {
            throw WebSocketException::handshakeFailed('Sec-WebSocket-Version 必须为 13');
        }

        // Origin 校验
        $allowed = $config['allowed_origins'] ?? ['*'];
        $origin = $headers['origin'] ?? null;
        if (! in_array('*', $allowed, true) && $origin !== null && ! in_array($origin, $allowed, true)) {
            throw WebSocketException::originNotAllowed($origin);
        }

        $accept = self::acceptKey($headers['sec-websocket-key']);

        $subprotocol = null;
        if (isset($headers['sec-websocket-protocol'])) {
            $requested = array_map('trim', explode(',', $headers['sec-websocket-protocol']));
            $supported = $config['subprotocols'] ?? [];
            foreach ($requested as $r) {
                if (in_array($r, $supported, true)) {
                    $subprotocol = $r;
                    break;
                }
            }
        }

        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$accept}\r\n";
        if ($subprotocol !== null) {
            $response .= "Sec-WebSocket-Protocol: {$subprotocol}\r\n";
        }
        $response .= "\r\n";

        return $response;
    }
}
