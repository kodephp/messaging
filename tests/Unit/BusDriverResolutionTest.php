<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use InvalidArgumentException;
use Kode\Messaging\Messaging;
use Kode\Messaging\PubSub\MemoryBus;
use PHPUnit\Framework\TestCase;

/**
 * 总线驱动名解析测试。
 *
 * 回归的故障：makeBus() 的 match default 分支是 MemoryBus，任何认不出的驱动名
 * （'redsi' 之类笔误、env 传进来的垃圾值）都会静默退化成进程内总线——跨 worker 不互通，
 * 消息说没就没，且没有任何异常可定位。现在：空白视为未指定，未知名字必须报错。
 */
final class BusDriverResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        Messaging::resetBuses();
        Messaging::configure([]);
    }

    protected function tearDown(): void
    {
        Messaging::resetBuses();
        Messaging::configure([]);
    }

    // ===================== 未指定时的回退链 =====================

    public function test_缺省配置回退到内存总线(): void
    {
        $this->assertInstanceOf(MemoryBus::class, Messaging::pubsub());
    }

    public function test_空白串与空白默认值一律回退到内存总线(): void
    {
        Messaging::configure(['pubsub' => ['default' => '   ']]);
        $this->assertInstanceOf(MemoryBus::class, Messaging::pubsub(null));
        $this->assertInstanceOf(MemoryBus::class, Messaging::pubsub(''));

        Messaging::configure(['pubsub' => []]);
        $this->assertInstanceOf(MemoryBus::class, Messaging::pubsub(' '));
    }

    public function test_非字符串的默认值不参与解析(): void
    {
        // 整数/数组配置是手改 config 文件时的常见产物，过去会被 (string)  cast 成驱动名再落进 default 分支。
        Messaging::configure(['pubsub' => ['default' => 123]]);
        $this->assertInstanceOf(MemoryBus::class, Messaging::pubsub());

        Messaging::configure(['pubsub' => ['default' => ['host' => '127.0.0.1']]]);
        $this->assertInstanceOf(MemoryBus::class, Messaging::pubsub());
    }

    // ===================== 显式名优先、以及空白如何「让位」 =====================

    public function test_显式驱动名优先于默认值(): void
    {
        // 默认值刻意配成非法名：能返回实例就说明它压根没被读到。
        Messaging::configure(['pubsub' => ['default' => 'bogus']]);
        $this->assertInstanceOf(MemoryBus::class, Messaging::pubsub('memory'));
    }

    public function test_空白显式名让位给默认值(): void
    {
        Messaging::configure(['pubsub' => ['default' => 'bogus']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bogus');
        Messaging::pubsub('  ');
    }

    public function test_驱动名两侧空白被容忍(): void
    {
        // 与未裁剪时必须是同一实例，否则说明 ' memory ' 走的是另一条解析路径。
        $this->assertSame(Messaging::pubsub('memory'), Messaging::pubsub(' memory '));
    }

    // ===================== 未知驱动名必须报错 =====================

    public function test_未知驱动名抛异常并列出可用驱动(): void
    {
        try {
            Messaging::pubsub('redsi');
            self::fail('未知驱动名应抛出 InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('未知的消息总线驱动 [redsi]', $e->getMessage());
            // 可用清单也得在消息里，报错后才是一眼可自修。
            foreach (Messaging::BUS_DRIVERS as $known) {
                self::assertStringContainsString($known, $e->getMessage());
            }
        }
    }

    public function test_默认值配错同样报错而非静默兜底(): void
    {
        Messaging::configure(['pubsub' => ['default' => 'rediv']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rediv');
        Messaging::pubsub();
    }

    public function test_合法驱动名集合与内置总线一致(): void
    {
        // 常量是报错文案的来源，新增总线时若漏在这里登记，提示会误导使用者。
        self::assertSame(['memory', 'channel', 'redis'], Messaging::BUS_DRIVERS);
    }
}
