<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\WebSocket\Codec\Handshake;
use PHPUnit\Framework\TestCase;

final class HandshakeTest extends TestCase
{
    public function test_client_request_format(): void
    {
        $req = Handshake::clientRequest('example.com:8080', '/ws', 'https://app.com', 'chat-v1');
        $this->assertStringStartsWith('GET /ws HTTP/1.1', $req);
        $this->assertStringContainsString('Host: example.com:8080', $req);
        $this->assertStringContainsString('Upgrade: websocket', $req);
        $this->assertStringContainsString('Connection: Upgrade', $req);
        $this->assertStringContainsString('Sec-WebSocket-Key:', $req);
        $this->assertStringContainsString('Sec-WebSocket-Version: 13', $req);
        $this->assertStringContainsString('Origin: https://app.com', $req);
        $this->assertStringContainsString('Sec-WebSocket-Protocol: chat-v1', $req);
    }

    public function test_accept_key_computation(): void
    {
        // 官方 RFC 6455 示例
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $expected = 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=';
        $this->assertSame($expected, Handshake::acceptKey($key));
    }

    public function test_server_response(): void
    {
        $request = "GET /ws HTTP/1.1\r\n"
            ."Host: example.com\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            ."\r\n";
        $response = Handshake::serverResponse($request);
        $this->assertStringStartsWith('HTTP/1.1 101 Switching Protocols', $response);
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response);
    }

    public function test_origin_check(): void
    {
        $request = "GET /ws HTTP/1.1\r\n"
            ."Host: example.com\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            ."Origin: https://evil.com\r\n"
            ."\r\n";
        $this->expectException(\Kode\Messaging\Exception\WebSocketException::class);
        Handshake::serverResponse($request, [
            'allowed_origins' => ['https://app.com'],
        ]);
    }
}
