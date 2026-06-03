<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Mqtt\Packet\Ack;
use Kode\Messaging\Adapter\Mqtt\Packet\Codec;
use Kode\Messaging\Adapter\Mqtt\Packet\Connect;
use Kode\Messaging\Adapter\Mqtt\Packet\PacketType;
use Kode\Messaging\Adapter\Mqtt\Packet\Publish;
use Kode\Messaging\Adapter\Mqtt\Packet\Subscribe;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\MqttException;

/**
 * MQTT 客户端适配器
 */
final class Client extends AbstractAdapter
{
    /** @var resource|null */
    private $stream = null;

    private ?MqttConnection $conn = null;

    private int $nextPacketId = 1;

    /** @var list<string> 待发订阅 */
    private array $pendingSubs = [];

    public static function scheme(): string
    {
        return 'mqtt';
    }

    public function version(): string
    {
        return $this->config['version'] ?? '3.1.1';
    }

    protected function defaultConfig(): array
    {
        return [
            'version'       => '3.1.1',
            'keepalive'     => 60,
            'clean_session' => true,
            'auto_reconnect' => true,
            'max_inflight'  => 1000,
        ];
    }

    public function connect(array $config = []): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 1883);
        $tls  = (bool)($config['tls'] ?? false);

        $errno = 0;
        $errstr = '';
        $remote = ($tls ? 'tls' : 'tcp') . "://{$host}:{$port}";
        $this->stream = @stream_socket_client($remote, $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT);
        if ($this->stream === false) {
            throw MqttException::connectFailed("无法连接 {$remote}: {$errstr}", [
                'host' => $host, 'port' => $port, 'errno' => $errno,
            ]);
        }
        stream_set_blocking($this->stream, true);

        $clientId = (string)($config['client_id'] ?? ('kode-' . bin2hex(random_bytes(4))));
        $will = $config['will'] ?? null;
        $connectPacket = Connect::encode(
            $clientId,
            $config['username'] ?? null,
            $config['password'] ?? null,
            (int)($config['keepalive'] ?? 60),
            (bool)($config['clean_session'] ?? true),
            $will,
            $this->version(),
        );
        @fwrite($this->stream, $connectPacket);

        // 等待 CONNACK
        $this->expectConnack();

        $this->conn = new MqttConnection(
            MqttConnection::generateId('mqtt'),
            'mqtt',
            stream_socket_get_name($this->stream, true) ?: "{$host}:{$port}",
            $this->stream,
        );
        $this->conn->setOnConnect(function () {
            // 自动重连后重新订阅
            foreach ($this->pendingSubs as $sub) {
                $this->sendSubscribe($sub[0] ?? []);
            }
        });

        return $this->conn;
    }

    public function listen(string $host, int $port): void
    {
        throw new \LogicException('MQTT Client 不支持 listen()');
    }

    public function run(): void
    {
        if ($this->conn === null) {
            $this->conn = $this->connect($this->config);
        }
        $this->readLoop();
    }

    private function readLoop(): void
    {
        $buf = '';
        while (!feof($this->stream)) {
            $chunk = @fread($this->stream, 4096);
            if ($chunk === false || $chunk === '') {
                usleep(10_000);
                continue;
            }
            $buf .= $chunk;
            // 解析完整包
            while (strlen($buf) >= 2) {
                $byte0 = ord($buf[0]);
                $type = ($byte0 >> 4) & 0x0F;
                $offset = 1;
                try {
                    $remainingLen = Codec::decodeRemainingLength($buf, $offset);
                } catch (MqttException) {
                    break;
                }
                if (strlen($buf) < $offset + $remainingLen) {
                    break; // 等更多数据
                }
                $body = substr($buf, $offset, $remainingLen);
                $headerByte = chr($byte0);
                $buf = substr($buf, $offset + $remainingLen);
                $this->dispatchPacket($type, $headerByte, $body);
            }
        }
    }

    private function dispatchPacket(int $type, string $headerByte, string $body): void
    {
        match ($type) {
            PacketType::PUBLISH => $this->handlePublish($headerByte, $body),
            PacketType::PUBACK,
            PacketType::PUBREC,
            PacketType::PUBREL,
            PacketType::PUBCOMP,
            PacketType::SUBACK,
            PacketType::UNSUBACK => $this->handleAck($type, $body),
            PacketType::PINGRESP => null, // 心跳
            PacketType::DISCONNECT => $this->conn?->close(0, 'disconnect'),
            default => null,
        };
    }

    private function handlePublish(string $headerByte, string $body): void
    {
        $info = Publish::decode($headerByte, $body);
        $this->conn?->dispatchPublish(
            $info['topic'],
            $info['payload'],
            $info['qos'],
            $info['retain'],
            $info['packet_id'],
        );
    }

    private function handleAck(int $type, string $body): void
    {
        $info = Ack::decode($type, $body);
        if (isset($info['packet_id'])) {
            $this->conn?->dispatchAck($info['packet_id'], $info);
        }
    }

    private function expectConnack(): void
    {
        stream_set_timeout($this->stream, 5);
        $buf = '';
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $chunk = @fread($this->stream, 4);
            if ($chunk === false || $chunk === '') {
                usleep(10_000);
                continue;
            }
            $buf .= $chunk;
            if (strlen($buf) >= 4) {
                break;
            }
        }
        if (strlen($buf) < 4) {
            throw MqttException::connectFailed('CONNACK 超时');
        }
        $type = (ord($buf[0]) >> 4) & 0x0F;
        if ($type !== PacketType::CONNACK) {
            throw MqttException::malformedPacket('期望 CONNACK，得到类型 ' . $type);
        }
        $ack = Ack::decode(PacketType::CONNACK, substr($buf, 2));
        if (($ack['return_code'] ?? 0) !== 0) {
            throw MqttException::authenticationFailed(['return_code' => $ack['return_code']]);
        }
    }

    /**
     * 客户端层发布（供 Builder 调用）。
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        $pid = 0;
        if ($qos > 0) {
            $pid = $this->nextPacketId++;
            if ($this->nextPacketId > 0xFFFF) {
                $this->nextPacketId = 1;
            }
        }
        @fwrite($this->stream, Publish::encode($topic, $payload, $qos, $retain, false, $pid));
    }

    public function sendSubscribe(array $topics): int
    {
        $pid = $this->nextPacketId++;
        if ($this->nextPacketId > 0xFFFF) {
            $this->nextPacketId = 1;
        }
        @fwrite($this->stream, Subscribe::encode($pid, $topics));
        $this->pendingSubs[] = [$topics, $pid];
        return $pid;
    }

    public function shutdown(): void
    {
        $this->running = false;
        if ($this->stream !== null) {
            @fwrite($this->stream, Codec::encodeFixedHeader(PacketType::DISCONNECT, 0, 0));
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public static function autoRegister(): void
    {
        Registry::register('mqtt', self::class);
    }
}
