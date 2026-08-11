<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Coap;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Connection\Connection;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\CoapException;
use Kode\Messaging\Message\Message as Msg;
use Kode\Messaging\Server\Builder as ServerBuilder;
use LogicException;

use function strlen;

/**
 * CoAP 服务端（RFC 7252）
 *
 * 适合 IoT 网关、传感器数据汇聚：
 *  - 资源以 URI-Path 标识
 *  - 请求方法映射为 GET/POST/PUT/DELETE
 *  - 支持 CON（可靠）/ NON（不可靠）两类交互
 *  - 自动按 Message ID 维护待确认请求，重传超时则触发 timeout 事件
 *
 * 用法：
 *   Messaging::server('coap://0.0.0.0:5683')
 *     ->on('message.received', function ($conn, $message) {
 *         $payload = $message->payload();      // string
 *         $method  = $message->headers()['coap.method']; // GET/POST/...
 *         $path    = $message->headers()['coap.path'];   // /sensors/temp
 *         // 业务处理后通过 $conn->sendRequest(...) 返回响应
 *     })
 *     ->start();
 */
final class Server extends AbstractAdapter
{
    /** @var null|resource */
    private $socket = null;

    /** @var array<string, CoapConnection> 对端地址 → 连接 */
    private array $connections = [];

    /** @var array<int, array{mid:int, peer:string, deadline:float, attempts:int}> 待确认请求 */
    private array $pending = [];

    private ?ServerBuilder $builder = null;

    public static function scheme(): string
    {
        return 'coap';
    }

    public function version(): string
    {
        return 'rfc7252';
    }

    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    protected function defaultConfig(): array
    {
        return [
            'max_packet_size' => 1_152,
            'ack_timeout_ms' => 2_000,
            'max_retransmit' => 4,
            'retransmit_backoff' => 2.0,
            'enable_observe' => true,   // RFC 7641
            'default_response_format' => CoapOption::FMT_JSON,
        ];
    }

    public function listen(string $host, int $port): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_server(
            "udp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND,
        );
        if ($this->socket === false) {
            throw CoapException::bindFailed($host, $port, (string) $errstr);
        }
        stream_set_timeout($this->socket, 1);
        $this->logger->info("CoAP listening on {$host}:{$port}");
    }

    public function run(): void
    {
        $this->running = true;
        $max = (int) ($this->config['max_packet_size'] ?? 1_152);
        $ackMs = (int) ($this->config['ack_timeout_ms'] ?? 2_000);
        $maxRetrans = (int) ($this->config['max_retransmit'] ?? 4);
        $backoff = (float) ($this->config['retransmit_backoff'] ?? 2.0);

        while ($this->running) {
            // 接收
            $buf = @stream_socket_recvfrom($this->socket, $max, 0, $peer);
            if ($buf !== false && $buf !== '') {
                $this->handleDatagram($buf, (string) $peer);
            }

            // 重传扫描
            $now = microtime(true) * 1000;
            foreach ($this->pending as $mid => $entry) {
                if ($now >= $entry['deadline']) {
                    if ($entry['attempts'] >= $maxRetrans) {
                        unset($this->pending[$mid]);
                        $this->builder?->emit('coap.timeout', [
                            'mid' => $mid,
                            'peer' => $entry['peer'],
                        ]);
                        continue;
                    }
                    // 触发事件让业务决定是否重传
                    $this->builder?->emit('coap.retransmit', [
                        'mid' => $mid,
                        'peer' => $entry['peer'],
                        'attempts' => $entry['attempts'] + 1,
                        'timeout' => $ackMs * ($backoff ** $entry['attempts']),
                    ]);
                    $this->pending[$mid]['attempts']++;
                    $this->pending[$mid]['deadline'] = $now + $ackMs * ($backoff ** $entry['attempts']);
                }
            }

            usleep(1_000);
        }
    }

    public function connect(array $config): ConnectionInterface
    {
        throw new LogicException('CoAP Server 不支持 connect()');
    }

    public function shutdown(): void
    {
        $this->running = false;
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('coap', self::class);
    }

    /**
     * 主动发送响应包到对端。
     */
    public function sendResponse(CoapConnection $conn, int $mid, float $code, string $payload = '', array $options = []): void
    {
        $type = $options['type'] ?? CoapType::ACK;
        $token = $options['token'] ?? '';
        $contentFormat = $options['content_format'] ?? (int) ($this->config['default_response_format'] ?? CoapOption::FMT_JSON);

        $optList = [];
        if ($payload !== '' && $contentFormat > 0) {
            $optList[] = ['number' => CoapOption::CONTENT_FORMAT, 'value' => chr($contentFormat)];
        }
        foreach ($options['extra'] ?? [] as $k => $v) {
            $optList[] = ['number' => (int) $k, 'value' => (string) $v];
        }
        usort($optList, fn($a, $b) => $a['number'] <=> $b['number']);

        $packet = new CoapPacket(
            type: $type,
            tokenLength: strlen($token),
            code: $code,
            messageId: $mid,
            token: $token,
            options: $optList,
            payload: $payload,
        );
        $conn->sendPacket($packet);
    }

    /**
     * 处理一个收到的 datagram。
     */
    private function handleDatagram(string $data, string $peer): void
    {
        try {
            $packet = CoapPacket::decode($data);
        } catch (CoapException $e) {
            $this->builder?->emit('error.protocol', ['error' => $e->getMessage(), 'peer' => $peer]);

            return;
        }

        if (! isset($this->connections[$peer])) {
            $this->connections[$peer] = new CoapConnection(
                Connection::generateId('coap'),
                'coap',
                $peer,
                $this->socket,
                $peer,
                reliable: true,
            );
            $this->builder?->emit('connection.open', ['connection' => $this->connections[$peer]]);
        }
        $conn = $this->connections[$peer];

        // ACK 是对先前 CON 的回应：从 pending 中移除
        if ($packet->type === CoapType::ACK || $packet->type === CoapType::RST) {
            unset($this->pending[$packet->messageId]);
            $this->builder?->emit('coap.ack', [
                'connection' => $conn,
                'packet' => $packet,
            ]);
            if ($packet->type === CoapType::RST) {
                return;
            }
        }

        $path = $this->extractUriPath($packet);
        $method = $this->codeToMethod($packet->code);

        $msg = Msg::fromRaw(
            $packet->payload,
            'coap',
            topic: $path,
            context: [
                'connection_id' => $conn->id(),
                'remote_address' => $peer,
                'coap' => [
                    'mid' => $packet->messageId,
                    'type' => $packet->type,
                    'type_name' => CoapType::name($packet->type),
                    'code' => $packet->code,
                    'code_name' => CoapCode::name($packet->code),
                    'method' => $method,
                    'path' => $path,
                    'token' => $packet->token,
                    'options' => $packet->options,
                ],
            ],
        );

        $this->builder?->emit('message.received', ['connection' => $conn, 'message' => $msg]);
    }

    private function extractUriPath(CoapPacket $packet): string
    {
        $segs = [];
        foreach ($packet->options as $opt) {
            if ($opt['number'] === CoapOption::URI_PATH) {
                $segs[] = $opt['value'];
            }
        }

        return '/'.implode('/', $segs);
    }

    private function codeToMethod(float $code): string
    {
        return match ($code) {
            CoapCode::GET => 'GET',
            CoapCode::POST => 'POST',
            CoapCode::PUT => 'PUT',
            CoapCode::DELETE => 'DELETE',
            default => 'CODE_'.number_format($code, 2, '_', ''),
        };
    }
}
