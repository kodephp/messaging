<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt;

use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Adapter\WebSocket\Codec\Frame;
use Kode\Messaging\Adapter\WebSocket\Codec\Handshake;
use Kode\Messaging\Adapter\WebSocket\Codec\OpCode;
use Kode\Messaging\Exception\WebSocketException;
use Kode\Messaging\Server\Builder as ServerBuilder;

/**
 * MQTT over WebSocket 服务端
 *
 * 让浏览器 / App 通过 WebSocket 连接（ws:// / wss://）承载 MQTT 3.1.1 协议。
 *
 * 工作原理：
 *   1. 客户端发起 WebSocket 握手，携带 Sec-WebSocket-Protocol: mqtt
 *   2. 服务端完成 101 升级，协商 subprotocol = mqtt
 *   3. 之后客户端发送的每个 WebSocket 二进制帧的 payload 即为一个 MQTT 包
 *   4. 服务端把 MQTT 响应包封装在 WebSocket 二进制帧中发回
 *
 * 典型场景：
 *   - 充电桩、手表等设备端用裸 MQTT（tcp://1883）
 *   - App / 网页管理后台用 MQTT over WebSocket（ws://8083）
 *   - 两种客户端连同一个 Broker，消息互通
 *
 * @see https://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html#_Toc398718127
 */
class MqttOverWsServer extends Server
{
    /** @var array<string, string> peer → 握手缓冲（未完成 WebSocket 升级的连接） */
    protected array $handshakeBuffers = [];

    /** @var array<string, string> peer → WebSocket 帧缓冲（已升级，等待完整帧） */
    protected array $wsFrameBuffers = [];

    /**
     * 协议 scheme。
     */
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
        Registry::register('mqtt+ws', self::class);
        Registry::register('mqtt+wss', self::class);
    }

    protected function defaultConfig(): array
    {
        return array_merge(parent::defaultConfig(), [
            'subprotocols'         => ['mqtt'],
            'allowed_origins'      => ['*'],
            'handshake_timeout'    => 10,
            'max_frame_size'       => 1_048_576,
        ]);
    }

    /**
     * 开始监听 TCP 端口（与父类相同，WebSocket 也是 TCP）。
     */
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
            throw new \RuntimeException("MQTT over WebSocket listen 失败: {$errstr}", $errno);
        }
        stream_set_blocking($this->socket, false);
        $this->logger->info("MQTT over WebSocket listening on {$host}:{$port}");
    }

    /**
     * 主事件循环。
     *
     * 与父类的区别：
     *   1. 新连接先做 WebSocket 握手（101 升级 + subprotocol 协商）
     *   2. 已升级连接读取 WebSocket 二进制帧，payload 喂给 MQTT 解析器
     *   3. 禁用 WebSocket 层心跳，由 MQTT PINGREQ 维持连接
     */
    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            // 1. 接受新连接
            $this->acceptNewConnections();

            // 2. 处理握手中的连接
            $this->processHandshakes();

            // 3. 读取已升级连接的 WebSocket 帧
            $this->processWsFrames();

            // 4. Keep Alive 超时检测
            $this->checkKeepAlive();

            usleep(1_000);
        }
    }

    /**
     * 接受新 TCP 连接，放入握手缓冲。
     */
    protected function acceptNewConnections(): void
    {
        $new = @stream_socket_accept($this->socket, 0);
        if ($new !== false) {
            $peer = stream_socket_get_name($new, true) ?: 'unknown';
            stream_set_blocking($new, false);
            $this->handshakeBuffers[$peer] = '';
            // 暂存 stream 资源到 connections 数组（握手完成后转为 MqttConnection）
            $this->connections[$peer] = new MqttConnection(
                MqttConnection::generateId('mqtt+ws'),
                'mqtt+ws',
                $peer,
                $new,
            );
            $this->lastActivity[$peer] = time();
        }
    }

    /**
     * 处理握手中的连接：读取 HTTP 请求，完成 WebSocket 升级。
     */
    protected function processHandshakes(): void
    {
        $peers = array_keys($this->handshakeBuffers);
        foreach ($peers as $peer) {
            $conn = $this->connections[$peer] ?? null;
            if ($conn === null) {
                unset($this->handshakeBuffers[$peer]);
                continue;
            }
            $sock = $conn->stream();
            if (!is_resource($sock)) {
                $this->removeHandshakeConnection($peer);
                continue;
            }

            $chunk = @fread($sock, 8192);
            if ($chunk === false || $chunk === '') {
                if (@feof($sock)) {
                    $this->removeHandshakeConnection($peer);
                }
                continue;
            }

            $this->handshakeBuffers[$peer] .= $chunk;
            $this->lastActivity[$peer] = time();

            // 检查是否已收到完整 HTTP 请求头
            $buffer = $this->handshakeBuffers[$peer];
            if (strpos($buffer, "\r\n\r\n") === false) {
                // 超时检测
                if (time() - $this->lastActivity[$peer] > ($this->config['handshake_timeout'] ?? 10)) {
                    $this->logger->warning('WebSocket 握手超时', ['peer' => $peer]);
                    $this->removeHandshakeConnection($peer);
                }
                continue;
            }

            // 完成 WebSocket 握手
            try {
                $response = Handshake::serverResponse($buffer, [
                    'allowed_origins' => $this->config['allowed_origins'] ?? ['*'],
                    'subprotocols'    => $this->config['subprotocols'] ?? ['mqtt'],
                ]);
                @fwrite($sock, $response);

                // 握手完成，初始化 MQTT 连接状态
                $this->buffers[$peer] = '';
                $this->keepAlive[$peer] = 0;
                $this->nextPacketId[$peer] = 1;
                $this->wsFrameBuffers[$peer] = '';
                unset($this->handshakeBuffers[$peer]);

                $this->logger->debug('WebSocket 握手完成，开始 MQTT 协议', ['peer' => $peer]);
            } catch (WebSocketException $e) {
                // 握手失败，返回 400 并关闭
                @fwrite($sock, "HTTP/1.1 400 Bad Request\r\n\r\n");
                $this->logger->warning('WebSocket 握手失败', [
                    'peer' => $peer,
                    'error' => $e->getMessage(),
                ]);
                $this->removeHandshakeConnection($peer);
            }
        }
    }

    /**
     * 读取已升级连接的 WebSocket 帧，payload 喂给 MQTT 解析器。
     */
    protected function processWsFrames(): void
    {
        $peers = array_keys($this->connections);
        foreach ($peers as $peer) {
            // 跳过还在握手中的连接
            if (isset($this->handshakeBuffers[$peer])) {
                continue;
            }

            $conn = $this->connections[$peer] ?? null;
            if ($conn === null) {
                continue;
            }
            $sock = $conn->stream();
            if (!is_resource($sock)) {
                $this->disconnectClient($peer, false);
                continue;
            }

            // 读取原始字节到帧缓冲
            $chunk = @fread($sock, 8192);
            if ($chunk === false || $chunk === '') {
                if (@feof($sock)) {
                    $this->disconnectClient($peer, false);
                }
                continue;
            }

            $this->wsFrameBuffers[$peer] .= $chunk;
            $this->lastActivity[$peer] = time();

            // 尝试从缓冲区解码完整的 WebSocket 帧
            while (strlen($this->wsFrameBuffers[$peer]) >= 2) {
                $frame = $this->tryDecodeFrame($peer);
                if ($frame === null) {
                    break; // 数据不足，等待更多字节
                }
                $this->onWsFrame($peer, $frame);
            }
        }
    }

    /**
     * 从帧缓冲区尝试解码一个完整的 WebSocket 帧。
     *
     * 成功时从缓冲区移除已消费的字节并返回 Frame；
     * 数据不足时返回 null。
     */
    protected function tryDecodeFrame(string $peer): ?Frame
    {
        $buffer = $this->wsFrameBuffers[$peer] ?? '';
        if (strlen($buffer) < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $fin = ($first & 0x80) !== 0;
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) !== 0;
        $payloadLen = $second & 0x7F;

        $headerLen = 2;
        if ($payloadLen === 126) {
            $headerLen += 2;
        } elseif ($payloadLen === 127) {
            $headerLen += 8;
        }
        if ($masked) {
            $headerLen += 4;
        }

        // 计算实际 payload 长度
        if ($payloadLen === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
        } elseif ($payloadLen === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
        }

        // 检查帧大小限制
        $maxFrame = $this->config['max_frame_size'] ?? 1_048_576;
        if ($payloadLen > $maxFrame) {
            $this->logger->error('WebSocket 帧过大', [
                'peer' => $peer,
                'size' => $payloadLen,
                'max' => $maxFrame,
            ]);
            $this->disconnectClient($peer, false);
            return null;
        }

        // 检查是否有完整的 payload
        if (strlen($buffer) < $headerLen + $payloadLen) {
            return null;
        }

        // 提取 mask 和 payload
        $mask = '';
        $extLen = $second & 0x7F;
        $offset = 2;
        if ($extLen === 126) {
            $offset = 4;
        } elseif ($extLen === 127) {
            $offset = 10;
        }

        if ($masked) {
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        $payload = substr($buffer, $offset, $payloadLen);

        // 解 mask
        if ($masked && $payload !== '') {
            $payload = $payload ^ str_repeat($mask, intdiv($payloadLen, 4) + 1);
            $payload = substr($payload, 0, $payloadLen);
        }

        // 从缓冲区移除已消费的字节
        $this->wsFrameBuffers[$peer] = substr($buffer, $headerLen + $payloadLen);

        return new Frame($fin, $opcode, $payload, $masked);
    }

    /**
     * 处理解码后的 WebSocket 帧。
     */
    protected function onWsFrame(string $peer, Frame $frame): void
    {
        switch ($frame->opcode) {
            case OpCode::BINARY:
            case OpCode::TEXT:
                // MQTT 包作为 WebSocket 二进制帧 payload
                $this->buffers[$peer] .= $frame->payload;
                $this->parseAndDispatch($peer);
                break;

            case OpCode::PING:
                // 回 PONG（保持 WebSocket 层兼容）
                $this->writeWsFrame($peer, Frame::pong($frame->payload)->encode(masked: false));
                break;

            case OpCode::PONG:
                // 忽略（MQTT 用 PINGREQ 做心跳）
                break;

            case OpCode::CLOSE:
                $this->disconnectClient($peer, true);
                break;

            default:
                // 未知 opcode，忽略
                break;
        }
    }

    /**
     * 覆盖父类的 write()：把 MQTT 包封装在 WebSocket 二进制帧中发送。
     */
    protected function write(string $peer, string $data): void
    {
        $conn = $this->connections[$peer] ?? null;
        if ($conn === null) {
            return;
        }
        $sock = $conn->stream();
        if (!is_resource($sock)) {
            return;
        }
        // MQTT 包 → WebSocket 二进制帧（服务端不 mask）
        $frame = Frame::binary($data)->encode(masked: false);
        @fwrite($sock, $frame);
    }

    /**
     * 直接写入 WebSocket 帧字节流（用于 PING/PONG 等控制帧）。
     */
    protected function writeWsFrame(string $peer, string $encodedFrame): void
    {
        $conn = $this->connections[$peer] ?? null;
        if ($conn === null) {
            return;
        }
        $sock = $conn->stream();
        if (!is_resource($sock)) {
            return;
        }
        @fwrite($sock, $encodedFrame);
    }

    /**
     * 移除握手中的连接。
     */
    protected function removeHandshakeConnection(string $peer): void
    {
        $conn = $this->connections[$peer] ?? null;
        if ($conn !== null) {
            $sock = $conn->stream();
            if (is_resource($sock)) {
                @fclose($sock);
            }
            unset($this->connections[$peer]);
        }
        unset($this->handshakeBuffers[$peer]);
    }

    public function shutdown(): void
    {
        parent::shutdown();
        // 关闭所有握手中的连接
        foreach (array_keys($this->handshakeBuffers) as $peer) {
            $this->removeHandshakeConnection($peer);
        }
    }
}
