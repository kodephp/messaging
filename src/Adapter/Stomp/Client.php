<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Stomp;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\StompException;
use LogicException;

/**
 * STOMP 客户端
 *
 * 用法：
 *   $client = Messaging::client('stomp://broker:61613');
 *   $client->subscribe('/queue/orders', function ($data) {
 *       echo $data['body'] . "\n";
 *   });
 *   $client->connect();
 *   $client->send('/queue/orders', 'hello');
 *   $client->loop();
 */
final class Client extends AbstractAdapter
{
    /** @var null|resource */
    private $stream = null;

    private ?StompConnection $conn = null;

    private string $buffer = '';

    /** @var array<string, string> subscription-id → destination */
    private array $subs = [];

    private int $nextSubId = 1;

    public static function scheme(): string
    {
        return 'stomp';
    }

    public function version(): string
    {
        return '1.2';
    }

    protected function defaultConfig(): array
    {
        return [
            'version' => '1.2',
            'login' => null,
            'passcode' => null,
            'client_id' => null,
            'heartbeat_ms' => 10_000,
        ];
    }

    public function connect(array $config = []): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 61613);
        $tls = (bool) ($config['tls'] ?? false);

        $remote = ($tls ? 'tls' : 'tcp')."://{$host}:{$port}";
        $errno = 0;
        $errstr = '';
        $this->stream = @stream_socket_client($remote, $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT);
        if ($this->stream === false) {
            throw StompException::connectFailed("无法连接 {$remote}: {$errstr}");
        }
        stream_set_timeout($this->stream, 1);

        $headers = [
            'host' => $host,
        ];
        if (! empty($config['login'])) {
            $headers['login'] = (string) $config['login'];
        }
        if (! empty($config['passcode'])) {
            $headers['passcode'] = (string) $config['passcode'];
        }
        if (! empty($config['client_id'])) {
            $headers['client-id'] = (string) $config['client_id'];
        }

        fwrite($this->stream, StompCodec::encodeStomp($headers));

        $this->conn = new StompConnection(
            StompConnection::generateId('stomp'),
            'stomp',
            stream_socket_get_name($this->stream, true) ?: "{$host}:{$port}",
            $this->stream,
        );

        return $this->conn;
    }

    public function listen(string $host, int $port): void
    {
        throw new LogicException('STOMP Client 不支持 listen()');
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
        while (! feof($this->stream)) {
            $chunk = @fread($this->stream, 4096);
            if ($chunk === false || $chunk === '') {
                usleep(10_000);
                continue;
            }
            $this->buffer .= $chunk;
            $this->consumeBuffer();
        }
    }

    private function consumeBuffer(): void
    {
        while (($nullPos = strpos($this->buffer, StompCodec::NULL)) !== false) {
            $frame = StompCodec::decodeFrame($this->buffer, 0);
            if ($frame === null) {
                return;
            }
            $this->buffer = substr($this->buffer, $frame['consumed']);
            $this->handleCommand($frame);
        }
    }

    private function handleCommand(array $frame): void
    {
        $command = $frame['command'];
        $headers = $frame['headers'];
        $body = $frame['body'];
        switch ($command) {
            case StompCodec::COMMAND_CONNECTED:
                // 已连接
                break;
            case StompCodec::COMMAND_MESSAGE:
                $this->conn?->dispatchMessage($headers, $body);
                break;
            case StompCodec::COMMAND_RECEIPT:
                // receipt-id 关联回执
                break;
            case StompCodec::COMMAND_ERROR:
                $this->logger->error('STOMP ERROR: '.($headers['message'] ?? '').' / '.$body);
                break;
            default:
                $this->logger->debug("STOMP 未知命令: {$command}");
        }
    }

    /**
     * 业务层 API：发送消息。
     *
     * @param array<string, string> $headers
     */
    public function send(string $destination, string $body, array $headers = []): void
    {
        @fwrite($this->stream, StompCodec::encodeSend($destination, $body, $headers));
    }

    /**
     * 业务层 API：订阅。
     *
     * @param array<string, string> $headers
     */
    public function subscribe(string $destination, callable $handler, array $headers = []): string
    {
        $subId = 'sub-'.$this->nextSubId++;
        @fwrite($this->stream, StompCodec::encodeSubscribe($destination, $subId, $headers));
        $this->subs[$subId] = $destination;
        $this->conn?->addSubscriptionHandler($subId, $handler);

        return $subId;
    }

    public function unsubscribe(string $subId): void
    {
        @fwrite($this->stream, StompCodec::encodeUnsubscribe($subId));
        unset($this->subs[$subId]);
    }

    public function ack(string $messageId, array $headers = []): void
    {
        @fwrite($this->stream, StompCodec::encodeAck($messageId, $headers));
    }

    public function shutdown(): void
    {
        $this->running = false;
        if ($this->stream !== null) {
            @fwrite($this->stream, StompCodec::encodeDisconnect());
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('stomp', self::class);
    }
}
