<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Server\Builder;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * `Builder::start()` 必须在 boot 之后把自身注入适配器。
 *
 * 旧实现只做了 boot/listen/run，SSE 的 interval 回调与 connection.open 事件因此永远收不到，
 * 调用方只能在外部手动 `$adapter->setBuilder($builder)` 绕过。
 */
final class ServerBuilderWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        Registry::unregister('wiring-test');
        WiringTestAdapter::$seenBuilder = null;
        WiringTestAdapter::$ran = false;
    }

    public function test_start_injects_builder_and_starts_adapter(): void
    {
        Registry::register('wiring-test', WiringTestAdapter::class);

        $builder = new Builder('wiring-test://127.0.0.1:0');
        $builder->interval(1000, static fn(): null => null);

        $builder->start();

        self::assertSame($builder, WiringTestAdapter::$seenBuilder);
        self::assertTrue(WiringTestAdapter::$ran);
        self::assertSame([1000], array_keys($builder->intervals()));
    }
}

final class WiringTestAdapter extends AbstractAdapter
{
    public static ?Builder $seenBuilder = null;

    public static bool $ran = false;

    public static function scheme(): string
    {
        return 'wiring-test';
    }

    public function setBuilder(Builder $builder): void
    {
        self::$seenBuilder = $builder;
    }

    public function listen(string $host, int $port): void {}

    public function connect(array $config): ConnectionInterface
    {
        throw new LogicException('server-only');
    }

    public function run(): void
    {
        self::$ran = true;
    }
}
