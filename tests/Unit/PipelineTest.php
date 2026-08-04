<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Contract\MiddlewareInterface;
use Kode\Messaging\Message\Message;
use Kode\Messaging\Middleware\Pipeline;
use PHPUnit\Framework\TestCase;

/**
 * 中间件管道（洋葱圈）单元测试
 *
 * 覆盖：
 *  - 洋葱顺序 A → B → C → handler → C → B → A（MiddlewareInterface 分支）
 *  - callable 中间件分支同样遵循洋葱顺序
 *  - 懒编译：同一管道多次 process() 复用已编译链，仅切换末端 handler
 *  - push() 变更中间件后自动失效并重建
 *  - count()
 */
final class PipelineTest extends TestCase
{
    /**
     * 可记录执行顺序的 MiddlewareInterface 实现。
     */
    private function recordingMiddleware(string $name, array &$log): MiddlewareInterface
    {
        $append = function (string $entry) use (&$log): void {
            $log[] = $entry;
        };
        return new class ($name, $append) implements MiddlewareInterface {
            public function __construct(
                private string $name,
                private \Closure $append,
            ) {
            }

            public function process(MessageInterface $message, callable $next): MessageInterface
            {
                ($this->append)('in:' . $this->name);
                $result = $next($message);
                ($this->append)('out:' . $this->name);
                return $result;
            }
        };
    }

    private function makeMessage(): MessageInterface
    {
        return Message::of(['hello' => 'world'], 'test');
    }

    // ===================== 洋葱顺序（MiddlewareInterface） =====================

    public function testOnionOrderWithMiddlewareObjects(): void
    {
        $log = [];
        $pipeline = new Pipeline();

        $pipeline->push($this->recordingMiddleware('A', $log));
        $pipeline->push($this->recordingMiddleware('B', $log));
        $pipeline->push($this->recordingMiddleware('C', $log));

        $pipeline->process($this->makeMessage(), function (MessageInterface $msg) use (&$log): MessageInterface {
            $log[] = 'handler';
            return $msg;
        });

        $this->assertSame(
            ['in:A', 'in:B', 'in:C', 'handler', 'out:C', 'out:B', 'out:A'],
            $log,
        );
    }

    // ===================== 洋葱顺序（callable 中间件） =====================

    public function testOnionOrderWithCallableMiddlewares(): void
    {
        $log = [];

        $mw = function (string $name) use (&$log): callable {
            return function (MessageInterface $msg, callable $next) use ($name, &$log): MessageInterface {
                $log[] = 'in:' . $name;
                $result = $next($msg);
                $log[] = 'out:' . $name;
                return $result;
            };
        };

        $pipeline = new Pipeline();
        $pipeline->push($mw('X'));
        $pipeline->push($mw('Y'));

        $pipeline->process($this->makeMessage(), function (MessageInterface $msg) use (&$log): MessageInterface {
            $log[] = 'handler';
            return $msg;
        });

        $this->assertSame(['in:X', 'in:Y', 'handler', 'out:Y', 'out:X'], $log);
    }

    // ===================== 懒编译：复用链、切换末端 handler =====================

    public function testLazyCompileReusesChainAcrossHandlers(): void
    {
        $log = [];
        $pipeline = new Pipeline();

        $pipeline->push($this->recordingMiddleware('A', $log));
        $pipeline->push($this->recordingMiddleware('B', $log));

        // 第一次 process，使用 handler#1
        $pipeline->process($this->makeMessage(), function (MessageInterface $msg) use (&$log): MessageInterface {
            $log[] = 'h1';
            return $msg;
        });

        // 第二次 process，使用 handler#2（链应被复用，中间件各再跑一次）
        $pipeline->process($this->makeMessage(), function (MessageInterface $msg) use (&$log): MessageInterface {
            $log[] = 'h2';
            return $msg;
        });

        $this->assertSame(
            [
                'in:A', 'in:B', 'h1', 'out:B', 'out:A',
                'in:A', 'in:B', 'h2', 'out:B', 'out:A',
            ],
            $log,
        );
    }

    // ===================== push() 失效并重建 =====================

    public function testPushInvalidatesCompiledChain(): void
    {
        $log = [];
        $pipeline = new Pipeline();

        $pipeline->push($this->recordingMiddleware('A', $log));
        $pipeline->process($this->makeMessage(), fn (MessageInterface $msg): MessageInterface => $msg);

        // 追加新中间件后，下一次 process 必须包含它
        $pipeline->push($this->recordingMiddleware('B', $log));
        $pipeline->process($this->makeMessage(), fn (MessageInterface $msg): MessageInterface => $msg);

        $this->assertSame(
            [
                'in:A', 'out:A',
                'in:A', 'in:B', 'out:B', 'out:A',
            ],
            $log,
        );
    }

    // ===================== 空管道：直接调用 handler =====================

    public function testEmptyPipelineInvokesHandlerDirectly(): void
    {
        $log = [];
        $pipeline = new Pipeline();

        $result = $pipeline->process(
            $this->makeMessage(),
            function (MessageInterface $msg) use (&$log): MessageInterface {
                $log[] = 'handler';
                return $msg;
            },
        );

        $this->assertSame(['handler'], $log);
        $this->assertInstanceOf(MessageInterface::class, $result);
    }

    // ===================== count =====================

    public function testCountReflectsMiddlewares(): void
    {
        $log = [];
        $pipeline = new Pipeline();
        $this->assertSame(0, $pipeline->count());

        $pipeline->push($this->recordingMiddleware('A', $log));
        $pipeline->push($this->recordingMiddleware('B', $log));
        $this->assertSame(2, $pipeline->count());

        $pipeline->pushAll([
            $this->recordingMiddleware('C', $log),
            $this->recordingMiddleware('D', $log),
        ]);
        $this->assertSame(4, $pipeline->count());
    }
}
