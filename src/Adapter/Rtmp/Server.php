<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Rtmp;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\RtmpException;
use Kode\Messaging\Message\Message as Msg;
use Kode\Messaging\Server\Builder as ServerBuilder;

/**
 * RTMP 服务端（嵌入式 / 直播源对接）
 *
> 适用：把 OBS / 直播客户端推送的 RTMP 流接入 kode/messaging，
> 业务层可以再分发到 WebSocket / SSE / UDP 等其他协议。
 *
> 不适用：作为 CDN 大规模 RTMP 分发（请用 nginx-rtmp / srs）。
 *
> 实现范围：handshake + chunk + AMF0 命令分发（connect / createStream / publish / play）。
> 不实现：FLV 封装、video/audio 解码。
 */
final class Server extends AbstractAdapter
{
    /** @var resource|null */
    private $socket = null;
    /** @var array<string, RtmpConnection> peer → connection */
    private array $connections = [];
    /** @var array<string, string> peer → 输入缓冲 */
    private array $buffers = [];
    /** @var array<int, int> peer → csid 4 状态 */
    private array $handshakeStep = [];
    private int $nextMessageStreamId = 1;
    private ?ServerBuilder $builder = null;

    public static function scheme(): string
    {
        return 'rtmp';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    protected function defaultConfig(): array
    {
        return [
            'chunk_size'         => 4096,
            'window_ack_size'    => 2_500_000,
            'peer_bandwidth'     => 2_500_000,
            'app'                => 'live',
        ];
    }

    public function listen(string $host, int $port): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        if ($this->socket === false) {
            throw RtmpException::serverError("listen 失败: {$errstr}");
        }
        stream_set_blocking($this->socket, false);
        $this->logger->info("RTMP listening on {$host}:{$port}");
    }

    public function run(): void
    {
        $this->running = true;
        while ($this->running) {
            $new = @stream_socket_accept($this->socket, 0);
            if ($new !== false) {
                $peer = stream_socket_get_name($new, true) ?: 'unknown';
                $this->connections[$peer] = new RtmpConnection(
                    RtmpConnection::generateId('rtmp'),
                    'rtmp',
                    $peer,
                    $new,
                );
                $this->buffers[$peer] = '';
                $this->handshakeStep[$peer] = 0;
                $this->builder?->emit('connection.open', ['connection' => $this->connections[$peer]]);
            }

            foreach ($this->connections as $peer => $conn) {
                $sock = $conn->stream();
                if (!is_resource($sock)) {
                    continue;
                }
                $chunk = @fread($sock, 4096);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $this->buffers[$peer] .= $chunk;
                $this->processPeer($peer);
            }

            usleep(1_000);
        }
    }

    public function connect(array $config): ConnectionInterface
    {
        throw new \LogicException('RTMP Server 不支持 connect()');
    }

    public function shutdown(): void
    {
        $this->running = false;
        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];
        $this->buffers = [];
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('rtmp', self::class);
    }

    private function processPeer(string $peer): void
    {
        $conn = $this->connections[$peer] ?? null;
        if ($conn === null) {
            return;
        }
        $sock = $conn->stream();
        if (!is_resource($sock)) {
            return;
        }
        $buf = &$this->buffers[$peer];

        // 握手阶段
        $step = $this->handshakeStep[$peer] ?? 0;
        if ($step === 0) {
            // 等待 C0+C1 = 1+1536 = 1537 字节
            if (strlen($buf) < 1537) {
                return;
            }
            $c0 = $buf[0];
            if ($c0 !== "\x03") {
                $this->builder?->emit('error.protocol', ['peer' => $peer, 'error' => 'C0 协议版本错误']);
                $this->closePeer($peer);
                return;
            }
            $c1 = substr($buf, 1, 1536);
            // 发送 S0+S1+S2
            @fwrite($sock, RtmpChunk::buildHandshakeResponse($c1));
            $buf = substr($buf, 1537);
            $this->handshakeStep[$peer] = 1;
        }
        if ($this->handshakeStep[$peer] === 1) {
            // 等待 C2 = 1536 字节
            if (strlen($buf) < 1536) {
                return;
            }
            $buf = substr($buf, 1536);
            $this->handshakeStep[$peer] = 2;
            // 发送协议控制消息（Set Chunk Size）
            $this->sendSetChunkSize($conn, (int)($this->config['chunk_size'] ?? 4096));
        }

        // chunk 解析
        $consumed = 0;
        $messages = $conn->parseChunks($buf, $consumed);
        $buf = substr($buf, $consumed);
        foreach ($messages as $msg) {
            $this->handleMessage($conn, $msg);
        }
    }

    private function sendSetChunkSize(RtmpConnection $conn, int $size): void
    {
        // Protocol Control Message 1 = Set Chunk Size (csid=2, type=0x01)
        $body = pack('N', $size);
        $conn->sendRtmpChunk(2, 0x01, 0, $body);
    }

    private function handleMessage(RtmpConnection $conn, array $msg): void
    {
        $type = $msg['type'];
        $body = $msg['body'];
        $csid = $msg['csid'];
        $messageStreamId = 0;

        switch ($type) {
            case 0x14: // AMF0 Command
                $this->handleAmf0Command($conn, $csid, $body);
                break;
            case 0x12: // AMF0 Data
                $this->handleAmf0Data($conn, $csid, $body);
                break;
            case 0x08: // Audio
            case 0x09: // Video
                $streamMessage = Msg::fromRaw(
                    $body,
                    'rtmp',
                    context: [
                        'connection_id'  => $conn->id(),
                        'remote_address' => $conn->remoteAddress(),
                        'rtmp_type'      => $type,
                        'timestamp'      => $msg['timestamp'],
                        'csid'           => $csid,
                    ],
                );
                $this->builder?->emit('message.received', ['connection' => $conn, 'message' => $streamMessage]);
                break;
            case 0x01: // Set Chunk Size
                $size = unpack('N', $body)[1] ?? 128;
                $conn->setChunkSizeIn($size);
                break;
            default:
                $this->logger->debug("RTMP 未知消息类型: 0x" . dechex($type));
        }
    }

    private function handleAmf0Command(RtmpConnection $conn, int $csid, string $body): void
    {
        $offset = 0;
        $commandName = Amf0::decode($body, $offset);
        $transactionId = Amf0::decode($body, $offset);
        $commandObject = Amf0::decode($body, $offset);
        $msg = Msg::fromRaw(
            $body,
            'rtmp',
            event: (string)$commandName,
            topic: (string)($commandObject['app'] ?? 'live'),
            context: [
                'connection_id'  => $conn->id(),
                'remote_address' => $conn->remoteAddress(),
                'command'        => $commandName,
                'transaction_id' => $transactionId,
                'command_object' => $commandObject,
            ],
        );
        $this->builder?->emit('message.received', ['connection' => $conn, 'message' => $msg]);

        // 协议级响应（最小实现）
        switch ($commandName) {
            case 'connect':
                // 发送 _result
                $respBody = Amf0::encode('_result') . Amf0::encode($transactionId)
                    . Amf0::encode([
                        'fmsVer'       => 'FMS/3,0,1,123',
                        'capabilities' => 31,
                    ])
                    . Amf0::encode([
                        'level'         => 'status',
                        'code'          => 'NetConnection.Connect.Success',
                        'description'   => 'Connection succeeded',
                        'objectEncoding' => 0,
                    ]);
                $conn->sendRtmpChunk(3, 0x14, 0, $respBody);
                break;
            case 'createStream':
                $streamId = $this->nextMessageStreamId++;
                $respBody = Amf0::encode('_result') . Amf0::encode($transactionId) . Amf0::encode(null) . Amf0::encode($streamId);
                $conn->sendRtmpChunk(3, 0x14, 0, $respBody);
                break;
            case 'publish':
                $this->respondStatus($conn, $transactionId, 'NetStream.Publish.Start', 'Publishing');
                break;
            case 'play':
                $this->respondStatus($conn, $transactionId, 'NetStream.Play.Start', 'Playing');
                break;
        }
    }

    private function handleAmf0Data(RtmpConnection $conn, int $csid, string $body): void
    {
        $offset = 0;
        $name = Amf0::decode($body, $offset);
        $payload = Amf0::decode($body, $offset);
        $msg = Msg::fromRaw(
            $body,
            'rtmp',
            event: (string)$name,
            context: [
                'connection_id'  => $conn->id(),
                'remote_address' => $conn->remoteAddress(),
                'data_name'      => $name,
                'data_payload'   => $payload,
            ],
        );
        $this->builder?->emit('message.received', ['connection' => $conn, 'message' => $msg]);
    }

    private function respondStatus(RtmpConnection $conn, float $txnId, string $code, string $description): void
    {
        $body = Amf0::encode('onStatus') . Amf0::encode(0) . Amf0::encode(null)
            . Amf0::encode([
                'level'       => 'status',
                'code'        => $code,
                'description' => $description,
            ]);
        $conn->sendRtmpChunk(3, 0x14, 0, $body);
    }

    private function closePeer(string $peer): void
    {
        $conn = $this->connections[$peer] ?? null;
        if ($conn !== null) {
            $conn->close();
        }
        unset($this->connections[$peer], $this->buffers[$peer], $this->handshakeStep[$peer]);
    }
}
