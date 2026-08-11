<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Nats;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\NatsException;
use Kode\Messaging\Message\Message as Msg;
use LogicException;

/**
 * NATS 客户端
 *
 * 用法：
 *   $client = Messaging::client('nats://broker:4222');
 *   $client->subscribe('orders.*', function ($subject, $payload, $msg) {
 *       echo "[$subject] $payload\n";
 *   });
 *   $client->connect();
 *   $client->publish('orders.created', json_encode(['id' => 1]));
 *   $client->loop();
 */
final class Client extends AbstractAdapter
{
    /** @var null|resource */
    private $stream = null;

    private ?NatsConnection $conn = null;

    private string $buffer = '';

    /** @var array<int, array{subject:string, queueGroup:?string}> sid → 订阅 */
    private array $subs = [];

    private int $nextSid = 1;

    public static function scheme(): string
    {
        return 'nats';
    }

    public function version(): string
    {
        return '1.0';
    }

    protected function defaultConfig(): array
    {
        return [
            'name' => 'kode-messaging',
            'pedantic' => false,
            'verbose' => false,
            'ping_interval' => 30,
            'max_payload' => 1_048_576,
        ];
    }

    public function connect(array $config = []): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 4222);
        $tls = (bool) ($config['tls'] ?? false);

        $remote = ($tls ? 'tls' : 'tcp')."://{$host}:{$port}";
        $errno = 0;
        $errstr = '';
        $this->stream = @stream_socket_client($remote, $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT);
        if ($this->stream === false) {
            throw NatsException::connectFailed("无法连接 {$remote}: {$errstr}", [
                'host' => $host, 'port' => $port, 'errno' => $errno,
            ]);
        }
        stream_set_timeout($this->stream, 1);

        // 等待服务端 INFO
        $info = $this->expectInfo(5.0);
        if (isset($info['max_payload'])) {
            $this->config['max_payload'] = (int) $info['max_payload'];
        }

        // 发送 CONNECT
        fwrite($this->stream, NatsCodec::encodeConnect([
            'name' => $this->config['name'],
            'pedantic' => $this->config['pedantic'],
            'verbose' => $this->config['verbose'],
            'protocol' => 1,
            'echo' => false,
        ]));

        $this->conn = new NatsConnection(
            NatsConnection::generateId('nats'),
            'nats',
            stream_socket_get_name($this->stream, true) ?: "{$host}:{$port}",
            $this->stream,
        );

        return $this->conn;
    }

    public function listen(string $host, int $port): void
    {
        throw new LogicException('NATS Client 不支持 listen()');
    }

    public function run(): void
    {
        if ($this->conn === null) {
            $this->connect($this->config);
        }
        $this->readLoop();
    }

    private function readLoop(): void
    {
        $lastPing = microtime(true);
        $pingInterval = (int) ($this->config['ping_interval'] ?? 30);

        while (! feof($this->stream)) {
            $now = microtime(true);
            if ($pingInterval > 0 && $now - $lastPing >= $pingInterval) {
                @fwrite($this->stream, NatsCodec::encodePing());
                $lastPing = $now;
            }

            $chunk = @fread($this->stream, 4096);
            if ($chunk === false || $chunk === '') {
                usleep(10_000);
                continue;
            }
            $this->buffer .= $chunk;
            $this->consumeBuffer();
        }
    }

    /**
     * 解析并消费缓冲区（按 CRLF 切分命令；PUB/MSG 单独处理 payload）。
     */
    private function consumeBuffer(): void
    {
        while ($this->buffer !== '') {
            // PUB/MSG/HPUB/HMSG 走"含 payload"解析
            $head = strtoupper(strtok($this->buffer, " \r\n"));
            if (in_array($head, ['PUB', 'MSG', 'HPUB', 'HMSG'], true)) {
                try {
                    $parsed = NatsCodec::parseWithPayload($this->buffer);
                } catch (NatsException $e) {
                    $this->logger->error('NATS 解析错误: '.$e->getMessage());
                    $this->buffer = '';

                    return;
                }
                if ($parsed === null) {
                    return; // 等更多数据
                }
                $this->buffer = substr($this->buffer, $parsed['parsed']);
                $this->handleCommand($parsed['command']);
                continue;
            }

            $crlf = strpos($this->buffer, NatsCodec::CRLF);
            if ($crlf === false) {
                return; // 等待更多数据
            }
            $line = substr($this->buffer, 0, $crlf);
            $this->buffer = substr($this->buffer, $crlf + 2);
            if ($line === '') {
                continue;
            }
            $cmd = NatsCodec::decodeCommand($line);
            $this->handleCommand($cmd);
        }
    }

    private function handleCommand(array $cmd): void
    {
        $op = $cmd['op'];
        $args = $cmd['args'];
        $payload = $cmd['payload'] ?? '';
        match ($op) {
            'INFO' => null, // 仅服务端，客户端已收到
            'MSG' => $this->handleMsg($args, $payload),
            'HMSG' => $this->handleMsg($args, $payload),
            'PING' => @fwrite($this->stream, NatsCodec::encodePong()),
            'PONG' => null,
            '+OK' => null,
            '-ERR' => $this->logger->error('NATS -ERR: '.($args[0] ?? '')),
            default => $this->logger->debug("NATS 未知操作: {$op}"),
        };
    }

    private function handleMsg(array $args, string $payload): void
    {
        // MSG <subject> <sid> [reply-to] <#bytes>
        if (count($args) < 2) {
            $this->logger->error('NATS MSG 参数缺失');

            return;
        }
        $subject = (string) $args[0];
        $sid = (int) $args[1];
        $replyTo = isset($args[2]) && ! ctype_digit((string) $args[2]) ? (string) $args[2] : null;
        $this->conn?->dispatchMessage($subject, $payload, $replyTo, $sid);
    }

    /**
     * 等待并解析服务端初始 INFO 行。
     *
     * @return array<string, mixed>
     */
    private function expectInfo(float $timeoutSec): array
    {
        $deadline = microtime(true) + $timeoutSec;
        stream_set_timeout($this->stream, (int) max(1, (int) $timeoutSec));
        while (microtime(true) < $deadline) {
            $pos = strpos($this->buffer, NatsCodec::CRLF);
            if ($pos !== false) {
                $line = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 2);
                if (str_starts_with($line, 'INFO ')) {
                    $json = trim(substr($line, 5));
                    $info = json_decode($json, true);

                    return is_array($info) ? $info : [];
                }
            }
            $chunk = @fread($this->stream, 4096);
            if ($chunk === false || $chunk === '') {
                usleep(10_000);
                continue;
            }
            $this->buffer .= $chunk;
        }

        return [];
    }

    /**
     * 业务层 API：发布（Builder 调用）。
     */
    public function publish(string $subject, string $payload, ?string $replyTo = null): void
    {
        $max = (int) ($this->config['max_payload'] ?? 1_048_576);
        if (strlen($payload) > $max) {
            throw NatsException::maxPayloadExceeded(strlen($payload), $max);
        }
        @fwrite($this->stream, NatsCodec::encodePub($subject, $payload, $replyTo));
    }

    /**
     * 业务层 API：订阅（Builder 调用）。
     */
    public function subscribe(string $subject, callable $handler, ?string $queueGroup = null): int
    {
        $sid = $this->nextSid++;
        if ($this->nextSid > 0xFFFF) {
            $this->nextSid = 1;
        }
        @fwrite($this->stream, NatsCodec::encodeSub($subject, $sid, $queueGroup));
        $this->subs[$sid] = ['subject' => $subject, 'queueGroup' => $queueGroup];
        $this->conn?->addSubjectHandler($subject, $handler);

        return $sid;
    }

    public function unsubscribe(int $sid, ?int $maxMsgs = null): void
    {
        @fwrite($this->stream, NatsCodec::encodeUnsub($sid, $maxMsgs));
        unset($this->subs[$sid]);
    }

    public function request(string $subject, string $payload, callable $handler, int $timeoutMs = 1000): void
    {
        $inbox = '_INBOX.'.bin2hex(random_bytes(8));
        $this->conn?->onReply($inbox, $handler);
        $this->subscribe($inbox, function (): void {});
        $this->publish($subject, $payload, $inbox);
    }

    public function shutdown(): void
    {
        $this->running = false;
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('nats', self::class);
    }
}
