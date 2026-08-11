<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt;

use Kode\Messaging\Adapter\Mqtt\Packet\Codec as MqttCodec;
use Kode\Messaging\Adapter\Mqtt\Packet\PacketType;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Adapter\WebSocket\ClientConnection;
use Kode\Messaging\Adapter\WebSocket\Codec\Frame;
use Kode\Messaging\Adapter\WebSocket\Codec\Handshake;
use Kode\Messaging\Adapter\WebSocket\Codec\OpCode;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\MqttException;

/**
 * MQTT over WebSocket 客户端
 *
 * 通过 WebSocket 连接（ws:// / wss://）承载 MQTT 3.1.1 协议。
 * 适用于 App / 网页管理后台等需要穿越防火墙的场景。
 *
 * 与 {@see Client}（裸 TCP MQTT 客户端）的区别：
 *   - 连接前先做 WebSocket 握手（Sec-WebSocket-Protocol: mqtt）
 *   - MQTT 包封装在 WebSocket 二进制帧中收发（客户端帧必须 mask）
 *   - 可穿越仅允许 80/443 端口的防火墙
 */
class MqttOverWsClient extends Client
{
    public static function scheme(): string
    {
        return 'mqtt+ws';
    }

    public function version(): string
    {
        return 'mqtt-3.1.1-over-ws';
    }

    public static function autoRegister(): void
    {
        Registry::register('mqtt+ws-client', self::class);
    }

    /**
     * 连接到 MQTT over WebSocket 服务端。
     *
     * @param array $config 配置（host, port, client_id, username, password, keep_alive, will_*）
     * @throws MqttException 连接或握手失败时抛出
     */
    public function connect(array $config = []): ConnectionInterface
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 8083;
        $path = $config['path'] ?? '/mqtt';
        $timeout = $config['timeout'] ?? 5.0;
        $tls = $config['tls'] ?? false;

        // 1. 建立 TCP 连接
        $errno = 0;
        $errstr = '';
        $remote = ($tls ? 'tls://' : 'tcp://')."{$host}:{$port}";
        $stream = @stream_socket_client($remote, $errno, $errstr, $timeout);
        if ($stream === false) {
            throw MqttException::serverError("WebSocket 连接失败: {$errstr}", [
                'host' => $host, 'port' => $port, 'errno' => $errno,
            ]);
        }
        stream_set_timeout($stream, (int) $timeout);

        // 2. WebSocket 握手（携带 Sec-WebSocket-Protocol: mqtt）
        $request = Handshake::clientRequest(
            "{$host}:{$port}",
            $path,
            $config['origin'] ?? "http://{$host}",
            'mqtt',
        );
        fwrite($stream, $request);

        // 读取握手响应
        $response = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (str_contains($response, "\r\n\r\n")) {
                break;
            }
        }

        if (! str_starts_with($response, 'HTTP/1.1 101')) {
            fclose($stream);

            throw MqttException::serverError('WebSocket 握手失败: 非 101 响应', [
                'response' => substr($response, 0, 200),
            ]);
        }

        // 3. 创建客户端连接（shouldMask = true）
        $peer = stream_socket_get_name($stream, true) ?: "{$host}:{$port}";
        $conn = new ClientConnection(
            ClientConnection::generateId('mqtt+ws'),
            'mqtt+ws',
            $peer,
            $stream,
        );

        // 4. 发送 MQTT CONNECT 包（封装在 WS 二进制帧中）
        $connectPacket = $this->buildConnectPacket($config);
        $this->writeWsFrame($conn, $connectPacket);

        // 5. 等待 CONNACK（从 WS 二进制帧中读取）
        $connack = $this->readMqttPacket($conn, $timeout);
        if ($connack === null || (ord($connack[0]) >> 4) !== (PacketType::CONNACK >> 4)) {
            throw MqttException::serverError('MQTT CONNACK 等待超时或无效', []);
        }

        // 检查返回码
        $returnCode = ord($connack[2] ?? "\x00");
        if ($returnCode !== 0) {
            throw MqttException::serverError("MQTT 连接被拒绝，返回码: {$returnCode}", [
                'code' => $returnCode,
            ]);
        }

        return $conn;
    }

    /**
     * 通过 WebSocket 帧发送 MQTT 包（客户端帧必须 mask）。
     */
    protected function writeWsFrame(ClientConnection $conn, string $mqttPacket): void
    {
        $frame = Frame::binary($mqttPacket)->encode(masked: true);
        fwrite($conn->stream(), $frame);
    }

    /**
     * 从 WebSocket 帧中读取一个 MQTT 包。
     *
     * @return null|string MQTT 包原始字节，超时返回 null
     */
    protected function readMqttPacket(ClientConnection $conn, float $timeout = 5.0): ?string
    {
        $deadline = microtime(true) + $timeout;
        $buffer = '';

        while (microtime(true) < $deadline) {
            // 尝试读取 WebSocket 帧
            $frame = $conn->readFrame(false); // 服务端帧不 mask
            if ($frame !== null) {
                if ($frame->opcode === OpCode::BINARY || $frame->opcode === OpCode::TEXT) {
                    return $frame->payload;
                }
                if ($frame->opcode === OpCode::CLOSE) {
                    return null;
                }
                // PING/PONG 等控制帧，忽略
                continue;
            }

            // 数据不足，等待
            usleep(10_000);
        }

        return null;
    }

    /**
     * 构建 MQTT CONNECT 包。
     *
     * @param array $config 配置
     * @return string CONNECT 包字节流
     */
    private function buildConnectPacket(array $config): string
    {
        $clientId = $config['client_id'] ?? 'auto-'.bin2hex(random_bytes(4));
        $keepAlive = (int) ($config['keep_alive'] ?? 60);
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $cleanSession = (bool) ($config['clean_session'] ?? true);

        // 可变头
        $variableHeader = MqttCodec::encodeString('MQTT'); // 协议名
        $variableHeader .= chr(4); // 协议级别 4 = MQTT 3.1.1

        // 连接标志
        $flags = 0;
        $flags |= $cleanSession ? 0x02 : 0;
        if ($username !== null) {
            $flags |= 0x80;
        }
        if ($password !== null) {
            $flags |= 0x40;
        }
        // 遗嘱消息
        if (isset($config['will_topic'])) {
            $flags |= 0x04;
            $flags |= (($config['will_qos'] ?? 0) & 0x03) << 3;
            if ($config['will_retain'] ?? false) {
                $flags |= 0x20;
            }
        }
        $variableHeader .= chr($flags);
        $variableHeader .= pack('n', $keepAlive);

        // 载荷
        $payload = MqttCodec::encodeString($clientId);
        if (isset($config['will_topic'])) {
            $payload .= MqttCodec::encodeString($config['will_topic']);
            $payload .= MqttCodec::encodeString($config['will_message'] ?? '');
        }
        if ($username !== null) {
            $payload .= MqttCodec::encodeString($username);
        }
        if ($password !== null) {
            $payload .= MqttCodec::encodeString($password);
        }

        // 固定头
        $remainingLength = strlen($variableHeader) + strlen($payload);
        $fixedHeader = chr(PacketType::CONNECT << 4);
        $fixedHeader .= MqttCodec::encodeRemainingLength($remainingLength);

        return $fixedHeader.$variableHeader.$payload;
    }
}
