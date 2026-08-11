<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\DTO\LimiterResult;
use Kode\Messaging\Adapter\Rtmp\Amf0;
use Kode\Messaging\Adapter\Rtmp\RtmpConnection;
use Kode\Messaging\Adapter\Rtmp\Server;
use Kode\Messaging\Event\Event;
use Kode\Messaging\Server\Builder as ServerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * RTMP 限流单元测试
 *
 * 覆盖：
 *  1. 连接级限流（按 IP）
 *  2. 命令级限流（按 connection_id）
 *  3. onLimited 回调
 *  4. rate_limit.exceeded 事件
 *  5. extractIp IPv4 / IPv6
 *  6. setRateLimiters 注入语义
 *  7. 限流键的隔离性
 */
final class RtmpServerRateLimitTest extends TestCase
{
    /** @var list<array{name:string, payload:array}> */
    private array $capturedEvents = [];

    protected function setUp(): void
    {
        $this->capturedEvents = [];
    }

    public function testSetRateLimitersStoresInstances(): void
    {
        $server = new Server(new NullLogger());
        $connLimiter = $this->makeAllowLimiter();
        $cmdLimiter = $this->makeAllowLimiter();

        $server->setRateLimiters($connLimiter, $cmdLimiter);

        $ref = new \ReflectionClass($server);
        $connProp = $ref->getProperty('connectionLimiter');
        $cmdProp = $ref->getProperty('commandLimiter');
        $this->assertSame($connLimiter, $connProp->getValue($server));
        $this->assertSame($cmdLimiter, $cmdProp->getValue($server));
    }

    public function testConnectionLimiterRejectsNewConnection(): void
    {
        $server = $this->buildServerWithBuilder();

        $connLimiter = $this->makeDenyLimiter();
        $server->setRateLimiters($connLimiter, null);

        [$serverR, $clientW] = $this->makeSocketPair();
        $this->invokeHandleNewConnection($server, $serverR, '192.168.1.10:54321');

        $names = array_column($this->capturedEvents, 'name');
        $this->assertContains('rate_limit.exceeded', $names);
        $this->assertContains('error.protocol', $names);

        $limited = $this->findEvent('rate_limit.exceeded');
        $this->assertSame('connection', $limited['payload']['type']);
        $this->assertSame('192.168.1.10:54321', $limited['payload']['peer']);
        $this->assertSame('kode/limiting', $limited['payload']['limiter']);
        $this->assertSame('192.168.1.10', $limited['payload']['info']['ip']);

        $this->closeQuietly($serverR);
        $this->closeQuietly($clientW);
    }

    public function testConnectionLimiterAllowsWhenAllow(): void
    {
        $server = $this->buildServerWithBuilder();

        $connLimiter = $this->makeAllowLimiter();
        $server->setRateLimiters($connLimiter, null);

        [$serverR, $clientW] = $this->makeSocketPair();
        $this->invokeHandleNewConnection($server, $serverR, '10.0.0.1:1234');

        $names = array_column($this->capturedEvents, 'name');
        $this->assertContains('connection.open', $names);
        $this->assertNotContains('rate_limit.exceeded', $names);

        $this->closeQuietly($serverR);
        $this->closeQuietly($clientW);
    }

    public function testCommandLimiterRejectsAmf0Command(): void
    {
        $server = $this->buildServerWithBuilder();

        $cmdLimiter = $this->makeDenyLimiter();
        $server->setRateLimiters(null, $cmdLimiter);

        [$serverR, $clientW] = $this->makeSocketPair();
        $conn = new RtmpConnection('rtmp-test-id-1', 'rtmp', '1.2.3.4:5555', $serverR);

        $body = Amf0::encode('connect') . Amf0::encode(1) . Amf0::encode(['app' => 'live']);
        $this->invokeHandleAmf0Command($server, $conn, 3, $body);

        $names = array_column($this->capturedEvents, 'name');
        $this->assertContains('rate_limit.exceeded', $names);
        $this->assertContains('error.protocol', $names);

        $limited = $this->findEvent('rate_limit.exceeded');
        $this->assertSame('command', $limited['payload']['type']);
        $this->assertSame('1.2.3.4:5555', $limited['payload']['peer']);
        $this->assertSame('rtmp-test-id-1', $limited['payload']['info']['connection_id']);

        $this->closeQuietly($serverR);
        $this->closeQuietly($clientW);
    }

    public function testOnLimitedCallbackIsInvoked(): void
    {
        $server = $this->buildServerWithBuilder();
        $connLimiter = $this->makeDenyLimiter();
        $server->setRateLimiters($connLimiter, null);

        $captured = null;
        $server->onLimited(function (array $payload) use (&$captured) {
            $captured = $payload;
        });

        [$serverR, $clientW] = $this->makeSocketPair();
        $this->invokeHandleNewConnection($server, $serverR, '10.0.0.99:6000');

        $this->assertNotNull($captured);
        $this->assertSame('connection', $captured['type']);
        $this->assertSame('kode/limiting', $captured['limiter']);

        $this->closeQuietly($serverR);
        $this->closeQuietly($clientW);
    }

    public function testOnLimitedCallbackErrorDoesNotBreakFlow(): void
    {
        $server = $this->buildServerWithBuilder();
        $connLimiter = $this->makeDenyLimiter();
        $server->setRateLimiters($connLimiter, null);

        $called = 0;
        $server->onLimited(function () use (&$called) {
            $called++;
            throw new \RuntimeException('boom');
        });
        $server->onLimited(function () use (&$called) {
            $called++;
        });

        [$serverR, $clientW] = $this->makeSocketPair();
        $this->invokeHandleNewConnection($server, $serverR, '127.0.0.1:7000');

        // 两个回调都应被调用（第二个不应被第一个的异常中断）
        $this->assertSame(2, $called);

        $this->closeQuietly($serverR);
        $this->closeQuietly($clientW);
    }

    public function testExtractIpHandlesIpv4(): void
    {
        $server = new Server(new NullLogger());
        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('extractIp');
        $this->assertSame('10.0.0.1', $method->invoke($server, '10.0.0.1:1234'));
    }

    public function testExtractIpHandlesIpv6(): void
    {
        $server = new Server(new NullLogger());
        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('extractIp');
        $this->assertSame('::1', $method->invoke($server, '[::1]:443'));
    }

    public function testExtractIpFallbackOnMalformedPeer(): void
    {
        $server = new Server(new NullLogger());
        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('extractIp');
        $this->assertSame('noport', $method->invoke($server, 'noport'));
    }

    public function testCommandLimiterKeyIsIsolated(): void
    {
        // 验证限流器被调用时使用 connection_id 作为 key 的一部分
        $server = $this->buildServerWithBuilder();

        $limiter = new class implements RateLimiterInterface {
            /** @var list<string> */
            public array $keys = [];
            public function allow(string $key, int $tokens = 1): bool
            {
                $this->keys[] = $key;
                return false;
            }
            public function check(string $key, int $tokens = 1): LimiterResult
            {
                return LimiterResult::denied(60.0, 1, 0.0);
            }
            public function consume(string $key, int $tokens = 1): LimiterResult
            {
                $this->keys[] = $key;
                return LimiterResult::denied(60.0, 1, 0.0);
            }
            public function getRemaining(string $key): float { return 0.0; }
            public function getWaitTime(string $key): float { return 60.0; }
            public function getCapacity(): int { return 1; }
            public function reset(string $key): void { $this->keys = []; }
        };
        $server->setRateLimiters(null, $limiter);

        [$serverR, $clientW] = $this->makeSocketPair();
        $conn = new RtmpConnection('rtmp-key-test', 'rtmp', '8.8.8.8:9999', $serverR);

        $body = Amf0::encode('connect') . Amf0::encode(1) . Amf0::encode(['app' => 'live']);
        $this->invokeHandleAmf0Command($server, $conn, 3, $body);

        $this->assertCount(1, $limiter->keys);
        $this->assertSame('rtmp:cmd:rtmp-key-test', $limiter->keys[0]);

        $this->closeQuietly($serverR);
        $this->closeQuietly($clientW);
    }

    public function testNoLimitersMeansNoRateLimitEvents(): void
    {
        $server = $this->buildServerWithBuilder();
        [$serverR, $clientW] = $this->makeSocketPair();
        $this->invokeHandleNewConnection($server, $serverR, '8.8.8.8:80');
        $names = array_column($this->capturedEvents, 'name');
        $this->assertNotContains('rate_limit.exceeded', $names);
        $this->assertContains('connection.open', $names);

        $this->closeQuietly($serverR);
        $this->closeQuietly($clientW);
    }

    public function testServerSchemeIsRtmp(): void
    {
        $this->assertSame('rtmp', Server::scheme());
    }

    public function testServerConnectThrows(): void
    {
        $server = new Server(new NullLogger());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('不支持 connect()');
        $server->connect([]);
    }

    public function testServerAutoRegisters(): void
    {
        Server::autoRegister();
        $this->assertSame(Server::class, \Kode\Messaging\Adapter\Registry::find('rtmp'));
    }

    // =================== 辅助方法 ===================

    /**
     * 构造一个带 mock builder 的 Server，并把 builder 的事件转发到 $this->capturedEvents。
     */
    private function buildServerWithBuilder(): Server
    {
        $builder = new ServerBuilder('rtmp://0.0.0.0:1935');
        $captured = &$this->capturedEvents;
        $builder->on('rate_limit.exceeded', function (Event $e) use (&$captured) {
            $captured[] = ['name' => $e->name, 'payload' => $e->payload];
        });
        $builder->on('error.protocol', function (Event $e) use (&$captured) {
            $captured[] = ['name' => $e->name, 'payload' => $e->payload];
        });
        $builder->on('connection.open', function (Event $e) use (&$captured) {
            $captured[] = ['name' => $e->name, 'payload' => $e->payload];
        });

        $server = new Server(new NullLogger());
        $server->setBuilder($builder);
        return $server;
    }

    /**
     * @return array{0: resource, 1: resource} [server-side, client-side]
     */
    private function makeSocketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->fail('无法创建 socket pair');
        }
        return $pair;
    }

    private function invokeHandleNewConnection(Server $server, $socket, string $peer): void
    {
        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('handleNewConnection');
        $method->invoke($server, $socket, $peer);
    }

    private function invokeHandleAmf0Command(Server $server, RtmpConnection $conn, int $csid, string $body): void
    {
        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('handleAmf0Command');
        $method->invoke($server, $conn, $csid, $body);
    }

    private function makeAllowLimiter(): RateLimiterInterface
    {
        return new class implements RateLimiterInterface {
            public function allow(string $key, int $tokens = 1): bool { return true; }
            public function check(string $key, int $tokens = 1): LimiterResult
            {
                return LimiterResult::allowed(INF, 1, 0.0);
            }
            public function consume(string $key, int $tokens = 1): LimiterResult
            {
                return LimiterResult::allowed(INF, 1, 0.0);
            }
            public function getRemaining(string $key): float { return INF; }
            public function getWaitTime(string $key): float { return 0.0; }
            public function getCapacity(): int { return 1; }
            public function reset(string $key): void {}
        };
    }

    private function makeDenyLimiter(): RateLimiterInterface
    {
        return new class implements RateLimiterInterface {
            public function allow(string $key, int $tokens = 1): bool { return false; }
            public function check(string $key, int $tokens = 1): LimiterResult
            {
                return LimiterResult::denied(60.0, 1, 0.0);
            }
            public function consume(string $key, int $tokens = 1): LimiterResult
            {
                return LimiterResult::denied(60.0, 1, 0.0);
            }
            public function getRemaining(string $key): float { return 0.0; }
            public function getWaitTime(string $key): float { return 60.0; }
            public function getCapacity(): int { return 1; }
            public function reset(string $key): void {}
        };
    }

    /**
     * @return array{name:string, payload:array}
     */
    private function findEvent(string $name): array
    {
        foreach ($this->capturedEvents as $e) {
            if ($e['name'] === $name) {
                return $e;
            }
        }
        $this->fail("未找到事件: {$name}");
    }

    private function closeQuietly(mixed $resource): void
    {
        if (\is_resource($resource)) {
            @\fclose($resource);
        }
    }
}
