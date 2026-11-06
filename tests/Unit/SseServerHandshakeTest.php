<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Sse\Server;
use Kode\Messaging\Adapter\Sse\SseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * SSE 服务端握手与定时器。
 *
 * 这里锁住三个曾让调用方不得不「手动接线」的行为：
 *  1. 握手要暴露 `Last-Event-ID`（业务侧续传的依据）；
 *  2. 握手要写入 keepalive 计时基准，否则 `checkAlive` 永远不触发；
 *  3. 多个 interval 各自独立计时，快定时器不能把慢定时器饿死。
 */
final class SseServerHandshakeTest extends TestCase
{
    public function test_handshake_exposes_last_event_id_and_keepalive_baseline(): void
    {
        $server = new Server();
        [$client, $serverSide] = $this->pair();
        fwrite($client, $this->request('/sse?room=1', ['Last-Event-ID' => 'stream-9:42']));

        $this->accept($server, $serverSide);
        $response = $this->readAll($client);

        self::assertStringContainsString('HTTP/1.1 200 OK', $response);
        self::assertStringContainsString('Content-Type: text/event-stream', $response);
        self::assertStringContainsString('X-Accel-Buffering: no', $response);
        self::assertStringContainsString('retry: 3000', $response);

        $conn = $this->firstConnection($server);
        self::assertSame('/sse?room=1', $conn->getAttribute('path'));
        self::assertSame('stream-9:42', $conn->getAttribute('lastEventId'));
        self::assertGreaterThan(0, (int) $conn->getAttribute('last_keepalive'));

        $conn->close();
        fclose($client);
    }

    public function test_handshake_without_last_event_id_does_not_invent_one(): void
    {
        $server = new Server();
        [$client, $serverSide] = $this->pair();
        fwrite($client, $this->request('/sse'));

        $this->accept($server, $serverSide);
        $this->readAll($client);

        $conn = $this->firstConnection($server);
        self::assertNull($conn->getAttribute('lastEventId'));

        $conn->close();
        fclose($client);
    }

    public function test_non_sse_request_is_rejected_with_406(): void
    {
        $server = new Server();
        [$client, $serverSide] = $this->pair();
        fwrite($client, $this->request('/sse', [], 'application/json'));

        $this->accept($server, $serverSide);
        $response = $this->readAll($client);

        self::assertStringContainsString('406 Not Acceptable', $response);
        self::assertSame([], $this->connections($server));

        fclose($client);
    }

    public function test_each_interval_keeps_its_own_schedule(): void
    {
        $server = new Server();
        $builder = new \Kode\Messaging\Server\Builder('sse://127.0.0.1:0');
        $server->setBuilder($builder);

        [$client, $serverSide] = $this->pair();
        $conn = new SseConnection('interval-test', 'sse', '127.0.0.1', $serverSide);
        $this->setConnections($server, [(int) $serverSide => $conn]);

        $fast = 0;
        $slow = 0;
        $start = microtime(true);

        $builder->interval(10, static function () use (&$fast, &$start, $server): void {
            $fast++;
            if (microtime(true) - $start > 1.0) {
                $server->shutdown();
            }
        });
        $builder->interval(100, static function () use (&$slow): void {
            $slow++;
        });

        $server->listen('127.0.0.1', 0);
        $server->run();

        self::assertGreaterThan(20, $fast, '10ms 定时器应持续触发');
        self::assertGreaterThanOrEqual(3, $slow, '100ms 定时器被 10ms 定时器饿死了');

        $conn->close();
        fclose($client);
    }

    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }

    /** @param resource $socket */
    private function accept(Server $server, $socket): void
    {
        $method = new ReflectionMethod(Server::class, 'acceptHttp');
        $method->invoke($server, $socket);
    }

    /** @return array<int, SseConnection> */
    private function connections(Server $server): array
    {
        $prop = new ReflectionProperty(Server::class, 'connections');

        return $prop->getValue($server);
    }

    private function firstConnection(Server $server): SseConnection
    {
        $connections = $this->connections($server);
        self::assertCount(1, $connections);

        return array_values($connections)[0];
    }

    /** @param array<int, SseConnection> $connections */
    private function setConnections(Server $server, array $connections): void
    {
        (new ReflectionProperty(Server::class, 'connections'))->setValue($server, $connections);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $path, array $headers = [], string $accept = 'text/event-stream'): string
    {
        $lines = ["GET {$path} HTTP/1.1", 'Host: 127.0.0.1', "Accept: {$accept}"];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $lines)."\r\n\r\n";
    }

    /** @param resource $client */
    private function readAll($client): string
    {
        stream_set_blocking($client, false);
        $out = '';
        for ($i = 0; $i < 200; $i++) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
        }

        return $out;
    }
}
