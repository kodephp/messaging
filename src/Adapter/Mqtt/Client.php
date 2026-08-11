<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Mqtt\Packet\Ack;
use Kode\Messaging\Adapter\Mqtt\Packet\Codec;
use Kode\Messaging\Adapter\Mqtt\Packet\Connect;
use Kode\Messaging\Adapter\Mqtt\Packet\PacketType;
use Kode\Messaging\Adapter\Mqtt\Packet\Properties;
use Kode\Messaging\Adapter\Mqtt\Packet\Publish;
use Kode\Messaging\Adapter\Mqtt\Packet\ReasonCode;
use Kode\Messaging\Adapter\Mqtt\Packet\Subscribe;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\MqttException;
use LogicException;

/**
 * MQTT 客户端适配器
 */
class Client extends AbstractAdapter
{
    /** @var null|resource */
    private $stream = null;

    private ?MqttConnection $conn = null;

    private int $nextPacketId = 1;

    /** @var list<array{0: array<string, int>, 1: int}> 待重连后重新订阅 */
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
            'version' => '3.1.1',
            'keepalive' => 60,
            'clean_session' => true,
            'auto_reconnect' => true,
            'max_inflight' => 1000,
        ];
    }

    public function connect(array $config = []): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int) ($config['port'] ?? 1883);
        $tls = (bool) ($config['tls'] ?? false);

        $errno = 0;
        $errstr = '';
        $remote = ($tls ? 'tls' : 'tcp')."://{$host}:{$port}";
        $this->stream = @stream_socket_client($remote, $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT);
        if ($this->stream === false) {
            throw MqttException::connectFailed("无法连接 {$remote}: {$errstr}", [
                'host' => $host, 'port' => $port, 'errno' => $errno,
            ]);
        }
        stream_set_blocking($this->stream, true);

        $clientId = (string) ($config['client_id'] ?? ('kode-'.bin2hex(random_bytes(4))));
        $will = $config['will'] ?? null;
        $version = $this->version();

        // MQTT 5.0: 构建 CONNECT Properties
        $connectProperties = [];
        if ($version === '5.0') {
            if (isset($config['session_expiry_interval'])) {
                $connectProperties[Properties::SESSION_EXPIRY_INTERVAL] = (int) $config['session_expiry_interval'];
            }
            if (isset($config['receive_maximum'])) {
                $connectProperties[Properties::RECEIVE_MAXIMUM] = (int) $config['receive_maximum'];
            }
            if (isset($config['maximum_packet_size'])) {
                $connectProperties[Properties::MAXIMUM_PACKET_SIZE] = (int) $config['maximum_packet_size'];
            }
            if (isset($config['topic_alias_maximum'])) {
                $connectProperties[Properties::TOPIC_ALIAS_MAXIMUM] = (int) $config['topic_alias_maximum'];
            }
            if (isset($config['user_properties'])) {
                $connectProperties[Properties::USER_PROPERTY] = $config['user_properties'];
            }
        }

        $connectPacket = Connect::encode(
            $clientId,
            $config['username'] ?? null,
            $config['password'] ?? null,
            (int) ($config['keepalive'] ?? 60),
            (bool) ($config['clean_session'] ?? true),
            $will,
            $version,
            $connectProperties,
        );
        @fwrite($this->stream, $connectPacket);

        // 等待 CONNACK（5.0 时解析 properties）
        $this->expectConnack($version);

        $this->conn = new MqttConnection(
            MqttConnection::generateId('mqtt'),
            'mqtt',
            stream_socket_get_name($this->stream, true) ?: "{$host}:{$port}",
            $this->stream,
        );

        return $this->conn;
    }

    public function listen(string $host, int $port): void
    {
        throw new LogicException('MQTT Client 不支持 listen()');
    }

    public function run(): void
    {
        if ($this->conn === null) {
            $conn = $this->connect($this->config);
            assert($conn instanceof MqttConnection);
            $this->conn = $conn;
        }
        $this->resubscribe();
        $this->readLoop();
    }

    /**
     * 重连后重新订阅所有 pendingSubs。
     */
    private function resubscribe(): void
    {
        foreach ($this->pendingSubs as $entry) {
            $this->sendSubscribe($entry[0]);
        }
    }

    private function readLoop(): void
    {
        $buf = '';
        while (! feof($this->stream)) {
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

    /**
     * 等待并解析 CONNACK。
     *
     * 3.1.1: Acknowledge Flags (1) + Return Code (1)
     * 5.0:   Acknowledge Flags (1) + Reason Code (1) + Properties (变长)
     *
     * @throws MqttException 连接失败或 CONNACK 错误
     */
    private function expectConnack(string $version = '3.1.1'): void
    {
        stream_set_timeout($this->stream, 5);
        $buf = '';
        $deadline = microtime(true) + 5;

        // 先读取固定头（至少 2 字节：type + remaining length）
        while (microtime(true) < $deadline) {
            $chunk = @fread($this->stream, 4096);
            if ($chunk === false || $chunk === '') {
                usleep(10_000);
                continue;
            }
            $buf .= $chunk;
            if (strlen($buf) >= 2) {
                break;
            }
        }
        if (strlen($buf) < 2) {
            throw MqttException::connectFailed('CONNACK 超时');
        }

        // 解析固定头
        $type = (ord($buf[0]) >> 4) & 0x0F;
        if ($type !== PacketType::CONNACK) {
            throw MqttException::malformedPacket('期望 CONNACK，得到类型 '.$type);
        }

        $offset = 1;
        $remainingLen = Codec::decodeRemainingLength($buf, $offset);

        // 确保包体完整
        $needed = $offset + $remainingLen;
        while (strlen($buf) < $needed && microtime(true) < $deadline) {
            $chunk = @fread($this->stream, 4096);
            if ($chunk === false || $chunk === '') {
                usleep(10_000);
                continue;
            }
            $buf .= $chunk;
        }
        if (strlen($buf) < $needed) {
            throw MqttException::connectFailed('CONNACK 包体不完整');
        }

        $body = substr($buf, $offset, $remainingLen);
        $bodyOffset = 0;

        // Acknowledge Flags
        $ackFlags = ord($body[$bodyOffset++]);
        $sessionPresent = ($ackFlags & 0x01) !== 0;

        // Return Code / Reason Code
        $code = ord($body[$bodyOffset++]);

        // MQTT 5.0: 解析 Properties
        $connackProperties = [];
        if ($version === '5.0' && $bodyOffset < strlen($body)) {
            $connackProperties = Properties::decode($body, $bodyOffset);
        }

        // 检查连接是否成功
        $isSuccess = $version === '5.0'
            ? ReasonCode::isSuccess($code)
            : $code === 0;

        if (! $isSuccess) {
            $description = $version === '5.0'
                ? ReasonCode::description($code)
                : match ($code) {
                    1 => ' unacceptable protocol version',
                    2 => 'identifier rejected',
                    3 => 'server unavailable',
                    4 => 'bad username or password',
                    5 => 'not authorized',
                    default => "unknown ({$code})",
                };

            throw MqttException::connectFailed("CONNACK 拒绝: {$description}", [
                'code' => $code,
                'version' => $version,
                'properties' => $connackProperties,
            ]);
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
