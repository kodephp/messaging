<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Client\Builder;
use Kode\Messaging\Exception\AdapterNotFoundException;
use Kode\Messaging\Tests\Unit\_fixtures\FailingAdapter;
use Kode\Messaging\Tests\Unit\_fixtures\InMemoryAdapter;
use Kode\Messaging\Tests\Unit\_fixtures\NoPublishAdapter;
use Kode\Messaging\Tests\Unit\_fixtures\NoSubscribeAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Client\Builder 边界条件测试
 *
 * 覆盖：
 *  1. connect() 幂等性
 *  2. connect() 抛异常时透传给调用方
 *  3. send() / publish() / subscribe() 在未连接场景的兜底
 *  4. publish() / subscribe() 在不支持的适配器上抛 LogicException
 *  5. disconnect() 后 connection 被清空
 *  6. 未注册协议时抛 AdapterNotFoundException
 *  7. 已关闭连接上 send() 抛 RuntimeException
 */
final class ClientBuilderTest extends TestCase
{
    private string $scheme = 'test-bld://127.0.0.1:0';

    protected function setUp(): void
    {
        // 重置计数并注册测试适配器，避免污染真实协议
        InMemoryAdapter::$connectCount = 0;
        InMemoryAdapter::$lastPublishCount = 0;
        Registry::register('test-bld', InMemoryAdapter::class);
        Registry::register('failing-adapter', FailingAdapter::class);
        Registry::register('no-subscribe', NoSubscribeAdapter::class);
        Registry::register('no-publish', NoPublishAdapter::class);
    }

    protected function tearDown(): void
    {
        Registry::unregister('test-bld');
        Registry::unregister('failing-adapter');
        Registry::unregister('no-subscribe');
        Registry::unregister('no-publish');
    }

    public function testConnectIsIdempotent(): void
    {
        $builder = new Builder($this->scheme);
        $a = $builder->connect();
        $b = $builder->connect();
        $this->assertSame($a, $b, 'connect() 第二次应返回同一连接实例');
    }

    public function testConnectEmitsOpenEventOnce(): void
    {
        $builder = new Builder($this->scheme);
        $count = 0;
        $builder->on('open', function () use (&$count) {
            $count++;
        });
        $builder->connect();
        $builder->connect();
        $this->assertSame(1, $count, 'open 事件只应触发一次');
    }

    public function testConnectFailurePropagatesException(): void
    {
        $builder = new Builder('failing-adapter://127.0.0.1:0');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mock connect failure');
        $builder->connect();
    }

    public function testSendBeforeConnectAutoConnects(): void
    {
        $builder = new Builder($this->scheme);
        $this->assertTrue($builder->send('hello'));
    }

    public function testSubscribeBeforeConnectAutoConnects(): void
    {
        $builder = new Builder($this->scheme);
        $sid = $builder->subscribe('topic/a', fn() => null);
        $this->assertSame(1, $sid);
    }

    public function testPublishBeforeConnectAutoConnects(): void
    {
        $builder = new Builder($this->scheme);
        $builder->publish('topic/a', 'payload');
        $this->assertSame(1, InMemoryAdapter::$lastPublishCount);
    }

    public function testSubscribeUnsupportedProtocolThrowsLogicException(): void
    {
        $builder = new Builder('no-subscribe://127.0.0.1:0');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('不支持 subscribe()');
        $builder->subscribe('t', fn() => null);
    }

    public function testPublishUnsupportedProtocolThrowsLogicException(): void
    {
        $builder = new Builder('no-publish://127.0.0.1:0');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('不支持 publish()');
        $builder->publish('t', 'p');
    }

    public function testDisconnectClearsConnection(): void
    {
        $builder = new Builder($this->scheme);
        $conn = $builder->connect();
        $this->assertNotNull($builder->connection());
        $builder->disconnect();
        $this->assertNull($builder->connection());
        $this->assertFalse($conn->isOpen());
    }

    public function testUnknownSchemeThrowsAdapterNotFound(): void
    {
        $builder = new Builder('nonexistent-scheme-xyz://127.0.0.1:0');
        $this->expectException(AdapterNotFoundException::class);
        $builder->connect();
    }

    public function testSendOnClosedConnectionThrows(): void
    {
        $builder = new Builder($this->scheme);
        $conn = $builder->connect();
        $conn->close();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/连接尚未建立或已关闭/');
        $builder->send('x');
    }
}
