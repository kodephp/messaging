<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Integration;

use Kode\Messaging\PubSub\RedisBus;
use PHPUnit\Framework\TestCase;
use Redis;
use Throwable;

/**
 * RedisBus 真机回路测试（子进程订阅 + 本进程发布）。
 *
 * 覆盖旧实现的致命故障：loop() 顺序遍历「每订阅一个阻塞 subscribe」的闭包，
 * 第一个闭包把循环卡死，第二个及之后的 topic 永远收不到消息。
 * 修复后所有 topic 由**一次** subscribe 覆盖，同一条消息只投一次。
 *
 * 无可达 redis 时整体跳过（见 redisUnavailable）。
 */
final class RedisBusLoopTest extends TestCase
{
    private const TIMEOUT = 6.0;

    private string $prefix = '';

    private string $dir;

    protected function setUp(): void
    {
        $reason = $this->redisUnavailable();
        if ($reason !== null) {
            $this->markTestSkipped($reason);
        }

        $this->dir = sys_get_temp_dir();
        $this->prefix = 'kodeit'.getmypid().':'.mt_rand().':';
    }

    protected function tearDown(): void
    {
        if (! isset($this->dir)) {
            return;
        }

        foreach (['ready', 'out', 'err'] as $f) {
            $path = $this->dir.'/kode_redis_'.$f.'_'.getmypid();
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function test_多topic由一次订阅共同收流(): void
    {
        $ready = $this->dir.'/kode_redis_ready_'.getmypid();
        $out = $this->dir.'/kode_redis_out_'.getmypid();
        $err = $this->dir.'/kode_redis_err_'.getmypid();
        @unlink($ready);
        @unlink($out);

        $child = proc_open(
            [PHP_BINARY, __DIR__.'/_fixtures/redis_subscriber.php', $this->prefix, $ready, $out],
            [1 => ['file', $err, 'a'], 2 => ['file', $err, 'a']],
            $pipes,
        );
        $this->assertIsResource($child, '子进程未能启动');

        try {
            $deadline = microtime(true) + self::TIMEOUT;
            while (! is_file($ready) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            $this->assertFileExists($ready, '订阅子进程未在时限内就绪：'.(is_file($err) ? (string) file_get_contents($err) : '无 stderr 输出'));

            $bus = new RedisBus($this->connectionConfig());
            $lines = [];
            $round = 0;

            // 子进程建立订阅有毫秒级窗口，逐轮重试发布（每轮消息 id 唯一）直到三个 topic 各自命中
            while (microtime(true) < $deadline && count($this->names($lines)) < 3) {
                ++$round;
                foreach (['one', 'two', 'three'] as $topic) {
                    $bus->publish('t/'.$topic, ['id' => $round]);
                }
                usleep(150_000);
                $lines = $this->lines($out);
            }

            // 再等一小段，让任何重复投递落到文件里（整文件重读，不做增量拼接）
            usleep(400_000);
            $lines = $this->lines($out);

            $this->assertSame(
                ['one', 'three', 'two'],
                $this->names($lines),
                '三个 topic 应各自收到消息；旧实现只有 t/one 收得到',
            );
            $this->assertSame(
                array_unique($lines),
                $lines,
                '同一条消息（topic+id）不得重复投递',
            );
        } finally {
            proc_terminate($child);
            proc_close($child);
        }
    }

    public function test_prefix参与channel命名且缺省不告警(): void
    {
        $bus = new RedisBus($this->connectionConfig());
        $bus->subscribe('orders:created', static fn() => null);

        $this->assertSame(
            ['orders:created' => $this->prefix.'orders:created'],
            $bus->channels(),
            '底层 channel 应为 prefix + topic',
        );

        $noPrefix = new RedisBus(['host' => $this->host(), 'port' => $this->port(), 'password' => $this->password()]);
        $noPrefix->subscribe('plain:topic', static fn() => null);
        // 旧实现直接读 $config['prefix']，未配 prefix 时触发 undefined array key 告警
        $this->assertSame(['plain:topic' => 'plain:topic'], $noPrefix->channels());
    }

    public function test_无订阅者时loop直接返回(): void
    {
        $bus = new RedisBus($this->connectionConfig());

        $bus->loop(3);
        $this->assertSame([], $bus->channels(), 'loop() 不应凭空产生订阅');
    }

    /** @return list<string> 子进程落盘的「topic:消息 id」行 */
    private function lines(string $out): array
    {
        if (! is_file($out)) {
            return [];
        }

        return array_values(array_filter(explode("\n", trim((string) file_get_contents($out)))));
    }

    /**
     * 命中的 topic 名（去重升序）。
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function names(array $lines): array
    {
        $names = [];
        foreach ($lines as $line) {
            $names[explode(':', $line, 2)[0]] = true;
        }
        $sorted = array_keys($names);
        sort($sorted);

        return $sorted;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(): array
    {
        return [
            'host' => $this->host(),
            'port' => $this->port(),
            'prefix' => $this->prefix,
            'password' => $this->password(),
        ];
    }

    private function host(): string
    {
        return (string) (getenv('KODE_TEST_REDIS_HOST') ?: '127.0.0.1');
    }

    private function port(): int
    {
        return (int) (getenv('KODE_TEST_REDIS_PORT') ?: 6379);
    }

    private function password(): ?string
    {
        $pw = getenv('KODE_TEST_REDIS_PASSWORD');

        return is_string($pw) && $pw !== '' ? $pw : null;
    }

    /** 返回跳过原因；null 表示环境可用。 */
    private function redisUnavailable(): ?string
    {
        if (! extension_loaded('redis')) {
            return '缺少 ext-redis，跳过 RedisBus 真机测试';
        }
        if (! function_exists('proc_open')) {
            return '禁用 proc_open，跳过 RedisBus 真机测试';
        }

        try {
            $r = new Redis();
            if (! @$r->connect($this->host(), $this->port(), 0.5)) {
                return 'redis 不可达（'.$this->host().':'.$this->port().'），跳过';
            }
            $pw = $this->password();
            if ($pw !== null) {
                $r->auth($pw);
            }
            $r->ping();
            $r->close();
        } catch (Throwable $e) {
            return 'redis 握手失败（'.$e->getMessage().'），跳过；需要鉴权时设 KODE_TEST_REDIS_PASSWORD';
        }

        return null;
    }
}
