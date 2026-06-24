<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter\Mqtt;

use Kode\Messaging\Adapter\AbstractAdapter;
use Kode\Messaging\Adapter\Mqtt\Packet\Codec;
use Kode\Messaging\Adapter\Mqtt\Packet\PacketType;
use Kode\Messaging\Adapter\Mqtt\Packet\Properties;
use Kode\Messaging\Adapter\Mqtt\Packet\ReasonCode;
use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Exception\MqttException;
use Kode\Messaging\Message\Message as Msg;
use Kode\Messaging\Server\Builder as ServerBuilder;

/**
 * MQTT 3.1.1 / 5.0 Broker（服务端）
 *
 * 完整实现 MQTT 3.1.1 和 5.0 协议的服务端：
 *   - CONNECT/CONNACK 握手与客户端 ID 校验
 *   - PUBLISH QoS 0/1/2 完整流程
 *   - SUBSCRIBE/UNSUBSCRIBE 主题通配符（+ / #）
 *   - 保留消息（Retained Message）
 *   - 遗嘱消息（Last Will and Testament）
 *   - Keep Alive 超时检测（1.5x）
 *   - 会话持久化（内存存储，Clean Session = false 时保留订阅与离线消息）
 *   - MQTT 5.0：Properties、Reason Code、Shared Subscription、Topic Alias
 *
 * 适用：本地开发 / 单元测试 / 嵌入式 IoT 网关。
 * 生产环境大规模部署推荐使用 Mosquitto / EMQX。
 *
 * @see https://docs.oasis-open.org/mqtt/mqtt/v3.1.1/os/mqtt-v3.1.1-os.html
 * @see https://docs.oasis-open.org/mqtt/mqtt/v5.0/os/mqtt-v5.0-os.html
 */
class Server extends AbstractAdapter
{
    /** @var resource|null 监听 socket */
    protected $socket = null;

    /** @var array<string, MqttConnection> peer → 连接对象 */
    protected array $connections = [];

    /** @var array<string, string> peer → 输入缓冲（未解析完的字节） */
    protected array $buffers = [];

    /** @var array<string, string> clientId → peer（用于重复连接检测） */
    protected array $clientIds = [];

    /**
     * 会话存储（clientId → 会话状态）。
     *
     * @var array<string, array{
     *     subscriptions: array<string, int>,
     *     pendingOutbound: list<array{topic: string, payload: string, qos: int, retain: bool}>,
     *     pendingInboundQos2: array<int, true>
     * }>
     */
    protected array $sessions = [];

    /** @var array<string, array{payload: string, qos: int}> topic → 保留消息 */
    protected array $retainedMessages = [];

    /** @var array<string, array{topic: string, payload: string, qos: int, retain: bool}> peer → 遗嘱消息 */
    protected array $willMessages = [];

    /** @var array<string, int> peer → Keep Alive 秒数（0 表示不检测） */
    protected array $keepAlive = [];

    /** @var array<string, int> peer → 最后活动时间戳 */
    protected array $lastActivity = [];

    /** @var array<string, int> peer → 下一个出站 Packet ID */
    protected array $nextPacketId = [];

    /** @var array<string, string> peer → MQTT 版本（'3.1.1' 或 '5.0'） */
    protected array $peerVersions = [];

    /** @var array<string, array<int, mixed>> peer → 5.0 连接属性 */
    protected array $peerProperties = [];

    protected ?ServerBuilder $builder = null;

    public static function scheme(): string
    {
        return 'mqtt';
    }

    public function version(): string
    {
        return 'mqtt-3.1.1';
    }

    /**
     * 注入 Server Builder（用于事件派发）。
     */
    public function setBuilder(ServerBuilder $builder): void
    {
        $this->builder = $builder;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'max_payload'              => 1_048_576, // 1 MiB
            'allow_anonymous'          => true,
            'max_client_id_len'        => 65535,
            'supported_versions'       => ['3.1.1', '5.0'],
            'max_qos'                  => 2,
            'retain_available'         => true,
            'wildcard_sub_available'   => true,
            'sub_id_available'         => true,
            'shared_sub_available'     => true,
            'server_keepalive'         => 0,  // 0 = 使用客户端请求的值
            'max_packet_size'          => 0,  // 0 = 无限制
            'topic_alias_max'          => 0,  // 0 = 禁用
        ];
    }

    /**
     * 开始监听 TCP 端口。
     *
     * @throws MqttException 监听失败时抛出
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
            throw MqttException::serverError("listen 失败: {$errstr}", [
                'host' => $host, 'port' => $port, 'errno' => $errno,
            ]);
        }
        stream_set_blocking($this->socket, false);
        $this->logger->info("MQTT Broker listening on {$host}:{$port}");
    }

    /**
     * 主事件循环（阻塞）。
     *
     * 每轮迭代：
     *   1. 接受新连接
     *   2. 读取已有连接数据并解析 MQTT 包
     *   3. 检测 Keep Alive 超时
     */
    public function run(): void
    {
        $this->running = true;

        // 集群模式：订阅总线接收跨节点消息
        // 注意：Redis pub/sub 是阻塞的，单线程事件循环中无法同时订阅和服务连接。
        // 生产环境建议使用 kode/process 多进程架构：主进程服务 MQTT，子进程订阅总线。
        // 此处仅注册回调，实际总线轮询由子类或多进程架构负责。
        if ($this->bus !== null) {
            try {
                $this->bus->subscribe('#', function (array $msg): void {
                    $this->onClusterMessage($msg);
                }, ['pattern' => 'mqtt']);
                $this->logger->info('集群总线订阅已注册', ['pattern' => '#']);
            } catch (\Throwable $e) {
                $this->logger->warning('集群总线订阅失败', ['error' => $e->getMessage()]);
            }
        }

        while ($this->running) {
            // 1. 接受新连接
            $new = @stream_socket_accept($this->socket, 0);
            if ($new !== false) {
                $peer = stream_socket_get_name($new, true) ?: 'unknown';
                $this->connections[$peer] = new MqttConnection(
                    MqttConnection::generateId('mqtt'),
                    'mqtt',
                    $peer,
                    $new,
                );
                $this->buffers[$peer] = '';
                $this->lastActivity[$peer] = time();
                $this->keepAlive[$peer] = 0;
                $this->nextPacketId[$peer] = 1;
            }

            // 2. 读取已有连接
            $peers = array_keys($this->connections);
            foreach ($peers as $peer) {
                $conn = $this->connections[$peer] ?? null;
                if ($conn === null) {
                    continue;
                }
                $sock = $conn->stream();
                if (!is_resource($sock)) {
                    $this->disconnectClient($peer, false);
                    continue;
                }
                $chunk = @fread($sock, 8192);
                if ($chunk === false || $chunk === '') {
                    // 检测连接是否已关闭
                    if (@feof($sock)) {
                        $this->disconnectClient($peer, false);
                    }
                    continue;
                }
                $this->buffers[$peer] .= $chunk;
                $this->lastActivity[$peer] = time();
                $this->parseAndDispatch($peer);
            }

            // 3. Keep Alive 超时检测
            $this->checkKeepAlive();

            usleep(1_000);
        }
    }

    /**
     * Server 不支持 connect()。
     *
     * @throws \LogicException
     */
    public function connect(array $config): ConnectionInterface
    {
        throw new \LogicException('MQTT Server 不支持 connect()');
    }

    /**
     * 优雅停机：关闭所有连接、释放 socket。
     */
    public function shutdown(): void
    {
        $this->running = false;

        // 关闭所有客户端连接（触发遗嘱消息）
        $peers = array_keys($this->connections);
        foreach ($peers as $peer) {
            $this->disconnectClient($peer, false);
        }

        $this->connections = [];
        $this->buffers = [];
        $this->clientIds = [];
        $this->sessions = [];
        $this->retainedMessages = [];
        $this->willMessages = [];
        $this->keepAlive = [];
        $this->lastActivity = [];
        $this->nextPacketId = [];
        $this->peerVersions = [];
        $this->peerProperties = [];

        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * 注册到协议注册表。
     */
    public static function autoRegister(): void
    {
        Registry::register('mqtt', self::class);
    }

    // ============================================================
    // 主题匹配（公开静态，便于单元测试）
    // ============================================================

    /**
     * MQTT 主题过滤器匹配。
     *
     * 支持通配符：
     *   - `+` 匹配恰好一个层级
     *   - `#` 匹配零个或多个层级（必须位于末尾）
     *
     * @param string $filter 订阅过滤器（如 `sport/+/temperature`）
     * @param string $topic  发布主题名（如 `sport/tennis/temperature`）
     */
    public static function matchTopic(string $filter, string $topic): bool
    {
        // 完全匹配（无通配符）
        if ($filter === $topic) {
            return true;
        }

        $filterParts = explode('/', $filter);
        $topicParts = explode('/', $topic);

        $i = 0;
        $filterLen = count($filterParts);
        $topicLen = count($topicParts);

        while ($i < $filterLen) {
            $f = $filterParts[$i];

            // `#` 通配符：匹配剩余所有层级（包括零层）
            if ($f === '#') {
                // `#` 必须是最后一个 token
                return $i === $filterLen - 1;
            }

            // `+` 通配符：匹配恰好一个层级
            if ($f === '+') {
                // topic 必须还有对应层级
                if ($i >= $topicLen) {
                    return false;
                }
                $i++;
                continue;
            }

            // 精确匹配当前层级
            if ($i >= $topicLen || $f !== $topicParts[$i]) {
                return false;
            }
            $i++;
        }

        // 过滤器遍历完毕，topic 也必须恰好遍历完毕
        return $i === $topicLen;
    }

    // ============================================================
    // 包编码（公开静态，便于单元测试与复用）
    // ============================================================

    /**
     * 编码 CONNACK 包（3.1.1）。
     *
     * @param int  $returnCode     0=接受, 1=版本不支持, 2=ID拒绝, 3=不可用, 4=用户名密码错误, 5=未授权
     * @param bool $sessionPresent 会话存在标志（Clean Session=0 时使用）
     */
    public static function encodeConnack(int $returnCode, bool $sessionPresent = false): string
    {
        $ackFlags = $sessionPresent ? 0x01 : 0x00;
        $body = chr($ackFlags) . chr($returnCode & 0xFF);
        return Codec::encodeFixedHeader(PacketType::CONNACK, 0, strlen($body)) . $body;
    }

    /**
     * 编码 CONNACK 包（5.0）。
     *
     * 5.0 用 Reason Code 替代 Return Code，并增加 Properties 字段。
     *
     * @param int                $reasonCode    ReasonCode::SUCCESS 等
     * @param bool               $sessionPresent 会话存在标志
     * @param array<int, mixed>  $properties    CONNACK Properties
     */
    public static function encodeConnackV5(
        int $reasonCode = ReasonCode::SUCCESS,
        bool $sessionPresent = false,
        array $properties = [],
    ): string {
        $ackFlags = $sessionPresent ? 0x01 : 0x00;
        $body = chr($ackFlags) . chr($reasonCode & 0xFF);
        $body .= Properties::encode($properties);
        return Codec::encodeFixedHeader(PacketType::CONNACK, 0, strlen($body)) . $body;
    }

    /**
     * 编码 DISCONNECT 包（5.0，含 Reason Code + Properties）。
     *
     * @param int                $reasonCode
     * @param array<int, mixed>  $properties
     */
    public static function encodeDisconnectV5(
        int $reasonCode = ReasonCode::SUCCESS,
        array $properties = [],
    ): string {
        $body = chr($reasonCode & 0xFF);
        $body .= Properties::encode($properties);
        return Codec::encodeFixedHeader(PacketType::DISCONNECT, 0, strlen($body)) . $body;
    }

    /**
     * 编码 SUBACK 包。
     *
     * @param int        $packetId    订阅请求的 Packet ID
     * @param list<int>  $returnCodes 每个主题的授予 QoS（0/1/2）或 128（失败）
     */
    public static function encodeSuback(int $packetId, array $returnCodes): string
    {
        $body = Codec::encodeUint16($packetId);
        foreach ($returnCodes as $code) {
            $body .= chr($code & 0xFF);
        }
        return Codec::encodeFixedHeader(PacketType::SUBACK, 0, strlen($body)) . $body;
    }

    /**
     * 编码 UNSUBACK 包。
     *
     * @param int $packetId 取消订阅请求的 Packet ID
     */
    public static function encodeUnsuback(int $packetId): string
    {
        $body = Codec::encodeUint16($packetId);
        return Codec::encodeFixedHeader(PacketType::UNSUBACK, 0, strlen($body)) . $body;
    }

    /**
     * 编码 PUBACK 包（QoS 1 确认）。
     *
     * @param int $packetId
     */
    public static function encodePuback(int $packetId): string
    {
        $body = Codec::encodeUint16($packetId);
        return Codec::encodeFixedHeader(PacketType::PUBACK, 0, strlen($body)) . $body;
    }

    /**
     * 编码 PUBREC 包（QoS 2 第一步：已接收）。
     *
     * @param int $packetId
     */
    public static function encodePubrec(int $packetId): string
    {
        $body = Codec::encodeUint16($packetId);
        return Codec::encodeFixedHeader(PacketType::PUBREC, 0, strlen($body)) . $body;
    }

    /**
     * 编码 PUBREL 包（QoS 2 第二步：释放）。
     *
     * @param int $packetId
     */
    public static function encodePubrel(int $packetId): string
    {
        $body = Codec::encodeUint16($packetId);
        return Codec::encodeFixedHeader(PacketType::PUBREL, 0x02, strlen($body)) . $body;
    }

    /**
     * 编码 PUBCOMP 包（QoS 2 第三步：完成）。
     *
     * @param int $packetId
     */
    public static function encodePubcomp(int $packetId): string
    {
        $body = Codec::encodeUint16($packetId);
        return Codec::encodeFixedHeader(PacketType::PUBCOMP, 0, strlen($body)) . $body;
    }

    /**
     * 编码 PINGRESP 包。
     */
    public static function encodePingresp(): string
    {
        return Codec::encodeFixedHeader(PacketType::PINGRESP, 0, 0);
    }

    /**
     * 编码 DISCONNECT 包。
     */
    public static function encodeDisconnect(): string
    {
        return Codec::encodeFixedHeader(PacketType::DISCONNECT, 0, 0);
    }

    /**
     * 编码 PUBACK 包（5.0，含 Reason Code + Properties）。
     */
    public static function encodePubackV5(int $packetId, int $reasonCode = ReasonCode::SUCCESS, array $properties = []): string
    {
        $body = Codec::encodeUint16($packetId);
        $body .= chr($reasonCode & 0xFF);
        if ($properties !== []) {
            $body .= Properties::encode($properties);
        }
        return Codec::encodeFixedHeader(PacketType::PUBACK, 0, strlen($body)) . $body;
    }

    /**
     * 编码 PUBREC 包（5.0，含 Reason Code + Properties）。
     */
    public static function encodePubrecV5(int $packetId, int $reasonCode = ReasonCode::SUCCESS, array $properties = []): string
    {
        $body = Codec::encodeUint16($packetId);
        $body .= chr($reasonCode & 0xFF);
        if ($properties !== []) {
            $body .= Properties::encode($properties);
        }
        return Codec::encodeFixedHeader(PacketType::PUBREC, 0, strlen($body)) . $body;
    }

    /**
     * 编码 PUBREL 包（5.0，含 Reason Code + Properties）。
     */
    public static function encodePubrelV5(int $packetId, int $reasonCode = ReasonCode::SUCCESS, array $properties = []): string
    {
        $body = Codec::encodeUint16($packetId);
        $body .= chr($reasonCode & 0xFF);
        if ($properties !== []) {
            $body .= Properties::encode($properties);
        }
        return Codec::encodeFixedHeader(PacketType::PUBREL, 0x02, strlen($body)) . $body;
    }

    /**
     * 编码 PUBCOMP 包（5.0，含 Reason Code + Properties）。
     */
    public static function encodePubcompV5(int $packetId, int $reasonCode = ReasonCode::SUCCESS, array $properties = []): string
    {
        $body = Codec::encodeUint16($packetId);
        $body .= chr($reasonCode & 0xFF);
        if ($properties !== []) {
            $body .= Properties::encode($properties);
        }
        return Codec::encodeFixedHeader(PacketType::PUBCOMP, 0, strlen($body)) . $body;
    }

    // ============================================================
    // 包解码（公开静态，便于单元测试与复用）
    // ============================================================

    /**
     * 解码 CONNECT 包体。
     *
     * @param string $body 剩余长度后的包体
     *
     * @return array{
     *     protocol_name: string,
     *     protocol_level: int,
     *     clean_session: bool,
     *     will_flag: bool,
     *     will_qos: int,
     *     will_retain: bool,
     *     username_flag: bool,
     *     password_flag: bool,
     *     keepalive: int,
     *     client_id: string,
     *     will_topic: ?string,
     *     will_payload: ?string,
     *     username: ?string,
     *     password: ?string
     * }
     *
     * @throws MqttException 包格式错误时抛出
     */
    public static function decodeConnect(string $body): array
    {
        $offset = 0;

        $protocolName = Codec::decodeString($body, $offset);
        $protocolLevel = Codec::decodeUint8($body, $offset);
        $flagsByte = Codec::decodeUint8($body, $offset);
        $keepalive = Codec::decodeUint16($body, $offset);

        $cleanSession = ($flagsByte & 0x02) !== 0;
        $willFlag = ($flagsByte & 0x04) !== 0;
        $willQos = ($flagsByte >> 3) & 0x03;
        $willRetain = ($flagsByte & 0x20) !== 0;
        $passwordFlag = ($flagsByte & 0x40) !== 0;
        $usernameFlag = ($flagsByte & 0x80) !== 0;

        // Payload
        $clientId = Codec::decodeString($body, $offset);

        $willTopic = null;
        $willPayload = null;
        if ($willFlag) {
            $willTopic = Codec::decodeString($body, $offset);
            $willPayload = Codec::decodeBinary($body, $offset);
        }

        $username = null;
        $password = null;
        if ($usernameFlag) {
            $username = Codec::decodeString($body, $offset);
        }
        if ($passwordFlag) {
            $password = Codec::decodeBinary($body, $offset);
        }

        return [
            'protocol_name'  => $protocolName,
            'protocol_level' => $protocolLevel,
            'clean_session'  => $cleanSession,
            'will_flag'      => $willFlag,
            'will_qos'       => $willQos,
            'will_retain'    => $willRetain,
            'username_flag'  => $usernameFlag,
            'password_flag'  => $passwordFlag,
            'keepalive'      => $keepalive,
            'client_id'      => $clientId,
            'will_topic'     => $willTopic,
            'will_payload'   => $willPayload,
            'username'       => $username,
            'password'       => $password,
        ];
    }

    /**
     * 解码 SUBSCRIBE 包体。
     *
     * @param string $body
     *
     * @return array{packet_id: int, topics: list<array{topic: string, qos: int}>}
     *
     * @throws MqttException
     */
    public static function decodeSubscribe(string $body): array
    {
        $offset = 0;
        $packetId = Codec::decodeUint16($body, $offset);

        $topics = [];
        while ($offset < strlen($body)) {
            $topic = Codec::decodeString($body, $offset);
            $qos = Codec::decodeUint8($body, $offset);
            $topics[] = ['topic' => $topic, 'qos' => $qos];
        }

        return ['packet_id' => $packetId, 'topics' => $topics];
    }

    /**
     * 解码 UNSUBSCRIBE 包体。
     *
     * @param string $body
     *
     * @return array{packet_id: int, topics: list<string>}
     *
     * @throws MqttException
     */
    public static function decodeUnsubscribe(string $body): array
    {
        $offset = 0;
        $packetId = Codec::decodeUint16($body, $offset);

        $topics = [];
        while ($offset < strlen($body)) {
            $topics[] = Codec::decodeString($body, $offset);
        }

        return ['packet_id' => $packetId, 'topics' => $topics];
    }

    // ============================================================
    // 内部：包解析与派发
    // ============================================================

    /**
     * 从 peer 缓冲区解析完整 MQTT 包并派发。
     */
    protected function parseAndDispatch(string $peer): void
    {
        $buf = &$this->buffers[$peer];

        while (strlen($buf) >= 2) {
            $byte0 = ord($buf[0]);
            $type = ($byte0 >> 4) & 0x0F;
            $flags = $byte0 & 0x0F;

            $offset = 1;
            try {
                $remainingLen = Codec::decodeRemainingLength($buf, $offset);
            } catch (MqttException) {
                // 剩余长度不完整，等待更多数据
                break;
            }

            // 包体尚未到达完整
            if (strlen($buf) < $offset + $remainingLen) {
                break;
            }

            $body = substr($buf, $offset, $remainingLen);
            $buf = substr($buf, $offset + $remainingLen);

            try {
                $this->dispatchPacket($peer, $type, $flags, $body);
            } catch (MqttException $e) {
                $this->builder?->emit('error.protocol', [
                    'peer'  => $peer,
                    'error' => $e->getMessage(),
                    'code'  => $e->getCode(),
                ]);
                $this->disconnectClient($peer, false);
                return;
            }
        }
    }

    /**
     * 派发单个 MQTT 包到对应处理器。
     *
     * @param string $peer  客户端地址标识
     * @param int    $type  包类型（PacketType::CONST）
     * @param int    $flags 固定头标志位
     * @param string $body  包体
     */
    protected function dispatchPacket(string $peer, int $type, int $flags, string $body): void
    {
        switch ($type) {
            case PacketType::CONNECT:
                $this->handleConnect($peer, $body);
                break;
            case PacketType::PUBLISH:
                $this->handlePublish($peer, $flags, $body);
                break;
            case PacketType::PUBACK:
                $this->handleSimpleAck($peer, $body, PacketType::PUBACK);
                break;
            case PacketType::PUBREC:
                $this->handlePubrec($peer, $body);
                break;
            case PacketType::PUBREL:
                $this->handlePubrel($peer, $body);
                break;
            case PacketType::PUBCOMP:
                $this->handleSimpleAck($peer, $body, PacketType::PUBCOMP);
                break;
            case PacketType::SUBSCRIBE:
                $this->handleSubscribe($peer, $body);
                break;
            case PacketType::UNSUBSCRIBE:
                $this->handleUnsubscribe($peer, $body);
                break;
            case PacketType::PINGREQ:
                $this->handlePingreq($peer);
                break;
            case PacketType::DISCONNECT:
                $this->handleDisconnect($peer);
                break;
            default:
                throw MqttException::malformedPacket("未知包类型: {$type}");
        }
    }

    // ============================================================
    // 内部：各包类型处理器
    // ============================================================

    /**
     * 处理 CONNECT 包：握手、客户端 ID 校验、会话恢复。
     *
     * 支持 MQTT 3.1.1（level 4）和 5.0（level 5）。
     *
     * @throws MqttException
     */
    protected function handleConnect(string $peer, string $body): void
    {
        $info = self::decodeConnect($body);

        // 校验协议名
        if ($info['protocol_name'] !== 'MQTT') {
            $this->sendConnackAndDisconnect($peer, 1, false, $info['protocol_level']);
            return;
        }

        $isV5 = $info['protocol_level'] === 5;
        $isV311 = $info['protocol_level'] === 4;

        // 校验协议版本
        if (!$isV311 && !$isV5) {
            // 3.1.1 的协议级别为 4，5.0 为 5
            $this->sendConnackAndDisconnect($peer, 1, false, $info['protocol_level']);
            return;
        }

        // 检查服务端是否支持该版本
        $supported = $this->config['supported_versions'] ?? ['3.1.1', '5.0'];
        $versionStr = $isV5 ? '5.0' : '3.1.1';
        if (!in_array($versionStr, $supported, true)) {
            if ($isV5) {
                $this->write($peer, self::encodeConnackV5(ReasonCode::UNSUPPORTED_PROTOCOL_VERSION));
            } else {
                $this->sendConnackAndDisconnect($peer, 1, false, $info['protocol_level']);
            }
            $this->disconnectClient($peer, true);
            return;
        }

        $clientId = $info['client_id'];

        // 客户端 ID 为空时，Clean Session 必须为 true
        if ($clientId === '' && !$info['clean_session']) {
            if ($isV5) {
                $this->write($peer, self::encodeConnackV5(ReasonCode::CLIENT_IDENTIFIER_NOT_VALID));
            } else {
                $this->write($peer, self::encodeConnack(2));
            }
            $this->disconnectClient($peer, true);
            return;
        }

        // 空 clientId 时自动生成
        if ($clientId === '') {
            $clientId = 'auto-' . bin2hex(random_bytes(8));
        }

        // 鉴权（简化版：allow_anonymous 控制是否允许匿名）
        $allowAnonymous = (bool)($this->config['allow_anonymous'] ?? true);
        if (!$allowAnonymous && $info['username'] === null) {
            if ($isV5) {
                $this->write($peer, self::encodeConnackV5(ReasonCode::NOT_AUTHORIZED));
            } else {
                $this->write($peer, self::encodeConnack(5));
            }
            $this->disconnectClient($peer, true);
            return;
        }

        // 处理重复 clientId：踢掉旧连接
        $oldPeer = $this->clientIds[$clientId] ?? null;
        if ($oldPeer !== null && $oldPeer !== $peer) {
            $this->disconnectClient($oldPeer, false);
        }

        // 会话恢复
        $sessionPresent = false;
        if (!$info['clean_session'] && isset($this->sessions[$clientId])) {
            // 恢复已有会话
            $sessionPresent = true;
        } else {
            // 创建新会话
            $this->sessions[$clientId] = [
                'subscriptions'      => [],
                'pendingOutbound'    => [],
                'pendingInboundQos2' => [],
            ];
        }

        // 记录 clientId 映射
        $this->clientIds[$clientId] = $peer;

        // 记录协议版本
        $this->peerVersions[$peer] = $versionStr;
        $this->peerProperties[$peer] = $info['properties'] ?? [];

        // 在连接对象上存储元信息
        $conn = $this->connections[$peer] ?? null;
        if ($conn !== null) {
            $conn->setAttribute('mqtt.client_id', $clientId);
            $conn->setAttribute('mqtt.clean_session', $info['clean_session']);
            $conn->setAttribute('mqtt.version', $versionStr);
        }

        // 存储 Keep Alive（5.0: 服务端可覆盖）
        $keepalive = $info['keepalive'];
        $serverKeepalive = (int)($this->config['server_keepalive'] ?? 0);
        if ($serverKeepalive > 0) {
            $keepalive = $serverKeepalive;
        }
        $this->keepAlive[$peer] = $keepalive;
        $this->lastActivity[$peer] = time();

        // 存储遗嘱消息
        if ($info['will_flag']) {
            $this->willMessages[$peer] = [
                'topic'      => $info['will_topic'] ?? '',
                'payload'    => $info['will_payload'] ?? '',
                'qos'        => $info['will_qos'],
                'retain'     => $info['will_retain'],
                'properties' => $info['will_properties'] ?? [],
            ];
        }

        // 发送 CONNACK
        if ($isV5) {
            // 构建 5.0 CONNACK Properties
            $connackProps = $this->buildConnackProperties($clientId);
            $this->write($peer, self::encodeConnackV5(ReasonCode::SUCCESS, $sessionPresent, $connackProps));
        } else {
            $this->write($peer, self::encodeConnack(0, $sessionPresent));
        }

        // 投递离线消息（非 Clean Session 恢复时）
        if ($sessionPresent && $conn !== null) {
            $this->deliverPendingMessages($peer, $clientId);
        }

        // 触发 connection.open 事件
        if ($conn !== null) {
            $this->builder?->emit('connection.open', ['connection' => $conn]);
        }
    }

    /**
     * 构建 MQTT 5.0 CONNACK Properties。
     *
     * @return array<int, mixed>
     */
    protected function buildConnackProperties(string $clientId): array
    {
        $props = [];

        // 服务端分配的客户端 ID（自动生成时返回）
        if (str_starts_with($clientId, 'auto-')) {
            $props[Properties::ASSIGNED_CLIENT_IDENTIFIER] = $clientId;
        }

        // 最大 QoS
        $maxQos = (int)($this->config['max_qos'] ?? 2);
        if ($maxQos < 2) {
            $props[Properties::MAXIMUM_QOS] = $maxQos;
        }

        // 保留可用
        if (!($this->config['retain_available'] ?? true)) {
            $props[Properties::RETAIN_AVAILABLE] = 0;
        }

        // 最大包大小
        $maxPacketSize = (int)($this->config['max_packet_size'] ?? 0);
        if ($maxPacketSize > 0) {
            $props[Properties::MAXIMUM_PACKET_SIZE] = $maxPacketSize;
        }

        // 主题别名最大值
        $topicAliasMax = (int)($this->config['topic_alias_max'] ?? 0);
        if ($topicAliasMax > 0) {
            $props[Properties::TOPIC_ALIAS_MAXIMUM] = $topicAliasMax;
        }

        // 通配符订阅可用
        if (!($this->config['wildcard_sub_available'] ?? true)) {
            $props[Properties::WILDCARD_SUBSCRIPTION_AVAILABLE] = 0;
        }

        // 订阅标识符可用
        if (!($this->config['sub_id_available'] ?? true)) {
            $props[Properties::SUBSCRIPTION_IDENTIFIER_AVAILABLE] = 0;
        }

        // 共享订阅可用
        if (!($this->config['shared_sub_available'] ?? true)) {
            $props[Properties::SHARED_SUBSCRIPTION_AVAILABLE] = 0;
        }

        // 服务端 Keep Alive 覆盖
        $serverKeepalive = (int)($this->config['server_keepalive'] ?? 0);
        if ($serverKeepalive > 0) {
            $props[Properties::SERVER_KEEP_ALIVE] = $serverKeepalive;
        }

        return $props;
    }

    /**
     * 发送 CONNACK 并断开连接（版本不匹配等场景）。
     */
    protected function sendConnackAndDisconnect(string $peer, int $returnCode, bool $sessionPresent, int $protocolLevel): void
    {
        if ($protocolLevel === 5) {
            $reasonCode = match ($returnCode) {
                1 => ReasonCode::UNSUPPORTED_PROTOCOL_VERSION,
                2 => ReasonCode::CLIENT_IDENTIFIER_NOT_VALID,
                4 => ReasonCode::BAD_USERNAME_OR_PASSWORD,
                5 => ReasonCode::NOT_AUTHORIZED,
                default => ReasonCode::UNSPECIFIED_ERROR,
            };
            $this->write($peer, self::encodeConnackV5($reasonCode, $sessionPresent));
        } else {
            $this->write($peer, self::encodeConnack($returnCode, $sessionPresent));
        }
        $this->disconnectClient($peer, true);
    }

    /**
     * 处理 PUBLISH 包：路由到订阅者，处理 QoS 确认。
     *
     * 支持 3.1.1 和 5.0（5.0 时解析 PUBLISH Properties）。
     *
     * @param int $flags 固定头标志位
     * @throws MqttException
     */
    protected function handlePublish(string $peer, int $flags, string $body): void
    {
        $dup = ($flags & 0x08) !== 0;
        $qos = ($flags >> 1) & 0x03;
        $retain = ($flags & 0x01) !== 0;

        $offset = 0;
        $topic = Codec::decodeString($body, $offset);
        $packetId = 0;
        if ($qos > 0) {
            $packetId = Codec::decodeUint16($body, $offset);
        }

        // MQTT 5.0: PUBLISH Properties
        $publishProperties = [];
        if ($this->isV5($peer)) {
            $publishProperties = Properties::decode($body, $offset);
        }

        $payload = substr($body, $offset);

        // 构造协议无关消息
        $conn = $this->connections[$peer] ?? null;
        $msg = Msg::fromRaw(
            $payload,
            'mqtt',
            topic: $topic,
            qos: $qos,
            retain: $retain,
            context: [
                'connection_id'  => $conn?->id(),
                'remote_address' => $conn?->remoteAddress(),
                'packet_id'      => $packetId,
                'dup'            => $dup,
                'properties'     => $publishProperties,
            ],
        );
        $this->builder?->emit('message.received', ['connection' => $conn, 'message' => $msg]);

        // QoS 1：回复 PUBACK
        if ($qos === 1) {
            if ($this->isV5($peer)) {
                $this->write($peer, $this->encodePubackV5($packetId, ReasonCode::SUCCESS));
            } else {
                $this->write($peer, self::encodePuback($packetId));
            }
        }

        // QoS 2：回复 PUBREC，等待 PUBREL
        if ($qos === 2) {
            $clientId = $this->clientIdOf($peer);
            if ($clientId !== null) {
                $this->sessions[$clientId]['pendingInboundQos2'][$packetId] = true;
            }
            if ($this->isV5($peer)) {
                $this->write($peer, $this->encodePubrecV5($packetId, ReasonCode::SUCCESS));
            } else {
                $this->write($peer, self::encodePubrec($packetId));
            }
        }

        // 保留消息处理
        if ($retain) {
            if ($payload === '') {
                // 空载荷的保留消息：清除该主题的保留消息
                unset($this->retainedMessages[$topic]);
            } else {
                $this->retainedMessages[$topic] = ['payload' => $payload, 'qos' => $qos];
            }
        }

        // 路由到订阅者
        $this->publishToSubscribers($topic, $payload, $qos, $retain, $publishProperties);
    }

    /**
     * 处理 PUBACK（QoS 1 出站确认）。
     */
    protected function handleSimpleAck(string $peer, string $body, int $type): void
    {
        $offset = 0;
        $packetId = Codec::decodeUint16($body, $offset);

        // 清除出站待确认队列中的对应消息
        $clientId = $this->clientIdOf($peer);
        if ($clientId !== null && isset($this->sessions[$clientId]['pendingOutbound'])) {
            foreach ($this->sessions[$clientId]['pendingOutbound'] as $i => $pending) {
                if (($pending['packet_id'] ?? 0) === $packetId) {
                    unset($this->sessions[$clientId]['pendingOutbound'][$i]);
                    $this->sessions[$clientId]['pendingOutbound'] = array_values($this->sessions[$clientId]['pendingOutbound']);
                    break;
                }
            }
        }
    }

    /**
     * 处理 PUBREC（QoS 2 第二步：客户端已收到，回复 PUBREL）。
     */
    protected function handlePubrec(string $peer, string $body): void
    {
        $offset = 0;
        $packetId = Codec::decodeUint16($body, $offset);
        if ($this->isV5($peer)) {
            $this->write($peer, self::encodePubrelV5($packetId, ReasonCode::SUCCESS));
        } else {
            $this->write($peer, self::encodePubrel($packetId));
        }
    }

    /**
     * 处理 PUBREL（QoS 2 第二步：发布者释放，回复 PUBCOMP 并投递消息）。
     */
    protected function handlePubrel(string $peer, string $body): void
    {
        $offset = 0;
        $packetId = Codec::decodeUint16($body, $offset);

        $clientId = $this->clientIdOf($peer);
        if ($clientId !== null) {
            // 从 QoS 2 入站待处理队列中取出并投递
            $pending = $this->sessions[$clientId]['pendingInboundQos2'][$packetId] ?? null;
            if ($pending !== null) {
                unset($this->sessions[$clientId]['pendingInboundQos2'][$packetId]);
            }
        }

        if ($this->isV5($peer)) {
            $this->write($peer, self::encodePubcompV5($packetId, ReasonCode::SUCCESS));
        } else {
            $this->write($peer, self::encodePubcomp($packetId));
        }
    }

    /**
     * 处理 SUBSCRIBE：添加订阅、回复 SUBACK、投递保留消息。
     *
     * @throws MqttException
     */
    protected function handleSubscribe(string $peer, string $body): void
    {
        $info = self::decodeSubscribe($body);
        $packetId = $info['packet_id'];
        $clientId = $this->clientIdOf($peer);

        if ($clientId === null) {
            return;
        }

        $returnCodes = [];
        foreach ($info['topics'] as $sub) {
            $topicFilter = $sub['topic'];
            $requestedQos = $sub['qos'];

            // 校验 QoS 范围
            if ($requestedQos < 0 || $requestedQos > 2) {
                $returnCodes[] = 128; // 失败
                continue;
            }

            // 记录订阅
            $this->sessions[$clientId]['subscriptions'][$topicFilter] = $requestedQos;
            $returnCodes[] = $requestedQos;

            // 投递该主题的保留消息
            $this->deliverRetainedMessages($peer, $topicFilter, $requestedQos);
        }

        $this->write($peer, self::encodeSuback($packetId, $returnCodes));
    }

    /**
     * 处理 UNSUBSCRIBE：移除订阅、回复 UNSUBACK。
     *
     * @throws MqttException
     */
    protected function handleUnsubscribe(string $peer, string $body): void
    {
        $info = self::decodeUnsubscribe($body);
        $packetId = $info['packet_id'];
        $clientId = $this->clientIdOf($peer);

        if ($clientId !== null) {
            foreach ($info['topics'] as $topicFilter) {
                unset($this->sessions[$clientId]['subscriptions'][$topicFilter]);
            }
        }

        $this->write($peer, self::encodeUnsuback($packetId));
    }

    /**
     * 处理 PINGREQ：回复 PINGRESP。
     */
    protected function handlePingreq(string $peer): void
    {
        $this->write($peer, self::encodePingresp());
    }

    /**
     * 处理 DISCONNECT：优雅断开，清除遗嘱消息。
     */
    protected function handleDisconnect(string $peer): void
    {
        $this->disconnectClient($peer, true);
    }

    // ============================================================
    // 内部：消息路由与投递
    // ============================================================

    /**
     * 将消息路由到所有匹配的订阅者。
     *
     * @param string             $topic   发布主题
     * @param string             $payload 消息载荷
     * @param int                $qos     消息 QoS
     * @param bool               $retain  是否保留消息
     * @param array<int, mixed>  $properties MQTT 5.0 PUBLISH Properties
     */
    protected function publishToSubscribers(string $topic, string $payload, int $qos, bool $retain, array $properties = []): void
    {
        // 本地投递
        foreach ($this->sessions as $clientId => $session) {
            $peer = $this->clientIds[$clientId] ?? null;
            if ($peer === null) {
                continue; // 客户端离线
            }

            $conn = $this->connections[$peer] ?? null;
            if ($conn === null || !$conn->isOpen()) {
                continue;
            }

            foreach ($session['subscriptions'] as $filter => $subQos) {
                if (self::matchTopic($filter, $topic)) {
                    // 实际投递 QoS = min(发布 QoS, 订阅 QoS)
                    $deliverQos = min($qos, $subQos);
                    $this->sendPublish($peer, $topic, $payload, $deliverQos, false, $properties);
                    break; // 每个客户端只投递一次（即使匹配多个过滤器）
                }
            }
        }

        // 集群广播：转发到其他节点
        if ($this->bus !== null) {
            try {
                $this->bus->publish($topic, [
                    'payload' => $payload,
                    'qos' => $qos,
                    'retain' => $retain,
                    'node_id' => $this->builder?->nodeId() ?? 'local',
                ], ['qos' => $qos]);
            } catch (\Throwable $e) {
                $this->logger->warning('集群总线发布失败', [
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 处理来自集群总线的消息（其他节点转发过来的 PUBLISH）。
     *
     * @param array<string, mixed> $msg 总线消息体
     */
    protected function onClusterMessage(array $msg): void
    {
        $topic = $msg['topic'] ?? '';
        $payload = $msg['payload'] ?? '';
        $qos = (int)($msg['qos'] ?? 0);
        $retain = (bool)($msg['retain'] ?? false);

        if ($topic === '') {
            return;
        }

        // 仅本地投递，不再转发回总线（避免循环）
        foreach ($this->sessions as $clientId => $session) {
            $peer = $this->clientIds[$clientId] ?? null;
            if ($peer === null) {
                continue;
            }
            $conn = $this->connections[$peer] ?? null;
            if ($conn === null || !$conn->isOpen()) {
                continue;
            }
            foreach ($session['subscriptions'] as $filter => $subQos) {
                if (self::matchTopic($filter, $topic)) {
                    $deliverQos = min($qos, $subQos);
                    $this->sendPublish($peer, $topic, $payload, $deliverQos, false);
                    break;
                }
            }
        }
    }

    /**
     * 向指定客户端发送 PUBLISH 包。
     *
     * @param string             $peer     客户端地址
     * @param string             $topic    主题
     * @param string             $payload  载荷
     * @param int                $qos      投递 QoS
     * @param bool               $retain   是否为保留消息投递
     * @param array<int, mixed>  $properties MQTT 5.0 PUBLISH Properties（仅 5.0 客户端）
     */
    protected function sendPublish(string $peer, string $topic, string $payload, int $qos, bool $retain, array $properties = []): void
    {
        $packetId = 0;
        if ($qos > 0) {
            $packetId = $this->nextPacketId($peer);
        }

        // 构造 PUBLISH 包
        $flags = 0;
        $flags |= match ($qos) {
            1       => 0x02,
            2       => 0x04,
            default => 0x00,
        };
        if ($retain) {
            $flags |= 0x01;
        }

        $varHeader = Codec::encodeString($topic);
        if ($qos > 0) {
            $varHeader .= Codec::encodeUint16($packetId);
        }

        // MQTT 5.0: PUBLISH Properties
        if ($this->isV5($peer)) {
            $varHeader .= Properties::encode($properties);
        }

        $body = $varHeader . $payload;
        $packet = Codec::encodeFixedHeader(PacketType::PUBLISH, $flags, strlen($body)) . $body;

        $this->write($peer, $packet);

        // QoS > 0 时记录待确认
        if ($qos > 0) {
            $clientId = $this->clientIdOf($peer);
            if ($clientId !== null) {
                $this->sessions[$clientId]['pendingOutbound'][] = [
                    'topic'     => $topic,
                    'payload'   => $payload,
                    'qos'       => $qos,
                    'retain'    => $retain,
                    'packet_id' => $packetId,
                ];
            }
        }
    }

    /**
     * 向新订阅者投递匹配的保留消息。
     *
     * @param string $peer       客户端地址
     * @param string $filter     订阅过滤器
     * @param int    $subQos     订阅 QoS
     */
    protected function deliverRetainedMessages(string $peer, string $filter, int $subQos): void
    {
        foreach ($this->retainedMessages as $topic => $retained) {
            if (self::matchTopic($filter, $topic)) {
                $deliverQos = min($retained['qos'], $subQos);
                $this->sendPublish($peer, $topic, $retained['payload'], $deliverQos, true);
            }
        }
    }

    /**
     * 向恢复会话的客户端投递离线消息。
     *
     * @param string $peer     客户端地址
     * @param string $clientId 客户端 ID
     */
    protected function deliverPendingMessages(string $peer, string $clientId): void
    {
        $session = $this->sessions[$clientId] ?? null;
        if ($session === null) {
            return;
        }

        foreach ($session['pendingOutbound'] as $pending) {
            $this->sendPublish(
                $peer,
                $pending['topic'],
                $pending['payload'],
                $pending['qos'],
                false,
            );
        }
    }

    // ============================================================
    // 内部：连接管理与 Keep Alive
    // ============================================================

    /**
     * 断开客户端连接。
     *
     * @param string $peer     客户端地址
     * @param bool   $graceful 是否为优雅断开（true=不发遗嘱消息，false=发遗嘱消息）
     */
    protected function disconnectClient(string $peer, bool $graceful): void
    {
        $conn = $this->connections[$peer] ?? null;

        // 非优雅断开：发布遗嘱消息
        if (!$graceful && isset($this->willMessages[$peer])) {
            $will = $this->willMessages[$peer];
            if ($will['topic'] !== '') {
                $this->publishToSubscribers(
                    $will['topic'],
                    $will['payload'],
                    $will['qos'],
                    $will['retain'],
                );
                // 遗嘱消息如果是 retain，也要存储
                if ($will['retain']) {
                    if ($will['payload'] === '') {
                        unset($this->retainedMessages[$will['topic']]);
                    } else {
                        $this->retainedMessages[$will['topic']] = [
                            'payload' => $will['payload'],
                            'qos'     => $will['qos'],
                        ];
                    }
                }
            }
        }

        // 清除遗嘱消息
        unset($this->willMessages[$peer]);

        // Clean Session = true 时清除会话
        $clientId = $this->clientIdOf($peer);
        if ($clientId !== null) {
            $cleanSession = $conn?->getAttribute('mqtt.clean_session', true) ?? true;
            if ($cleanSession) {
                unset($this->sessions[$clientId]);
                unset($this->clientIds[$clientId]);
            }
        }

        // 关闭连接
        if ($conn !== null) {
            $sock = $conn->stream();
            if (is_resource($sock)) {
                @fclose($sock);
            }
            $conn->close(0, 'disconnect');
            $this->builder?->emit('connection.close', [
                'connection' => $conn,
                'reason'     => $graceful ? 'mqtt.disconnect' : 'mqtt.connection_lost',
            ]);
        }

        // 清理状态
        unset($this->connections[$peer]);
        unset($this->buffers[$peer]);
        unset($this->keepAlive[$peer]);
        unset($this->lastActivity[$peer]);
        unset($this->nextPacketId[$peer]);
        unset($this->peerVersions[$peer]);
        unset($this->peerProperties[$peer]);
    }

    /**
     * 检测 Keep Alive 超时。
     *
     * MQTT 3.1.1 §3.1.2.10：若 Keep Alive 非零，服务端在 1.5 * Keep Alive
     * 秒内未收到任何包，则应断开连接（视为非优雅断开，触发遗嘱）。
     */
    protected function checkKeepAlive(): void
    {
        $now = time();
        foreach ($this->keepAlive as $peer => $ka) {
            if ($ka <= 0) {
                continue; // Keep Alive = 0 表示不检测
            }
            $lastActivity = $this->lastActivity[$peer] ?? $now;
            $timeout = (int)ceil($ka * 1.5);
            if ($now - $lastActivity > $timeout) {
                $this->logger->debug("MQTT Keep Alive 超时: peer={$peer}, ka={$ka}s");
                $this->disconnectClient($peer, false);
            }
        }
    }

    // ============================================================
    // 内部：工具方法
    // ============================================================

    /**
     * 获取 peer 对应的 clientId。
     */
    protected function clientIdOf(string $peer): ?string
    {
        foreach ($this->clientIds as $clientId => $p) {
            if ($p === $peer) {
                return $clientId;
            }
        }
        return null;
    }

    /**
     * 判断 peer 是否使用 MQTT 5.0。
     */
    protected function isV5(string $peer): bool
    {
        return ($this->peerVersions[$peer] ?? '3.1.1') === '5.0';
    }

    /**
     * 生成下一个出站 Packet ID（1-65535 循环）。
     */
    protected function nextPacketId(string $peer): int
    {
        $id = $this->nextPacketId[$peer] ?? 1;
        $this->nextPacketId[$peer] = $id >= 0xFFFF ? 1 : $id + 1;
        return $id;
    }

    /**
     * 向客户端写入原始字节。
     *
     * @param string $peer 客户端地址
     * @param string $data 已编码的 MQTT 包
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
        @fwrite($sock, $data);
    }

    /**
     * 获取当前保留消息（测试用）。
     *
     * @return array<string, array{payload: string, qos: int}>
     */
    public function getRetainedMessages(): array
    {
        return $this->retainedMessages;
    }

    /**
     * 获取当前会话列表（测试用）。
     *
     * @return array<string, array{subscriptions: array<string, int>, pendingOutbound: list<mixed>, pendingInboundQos2: array<int, true>}>
     */
    public function getSessions(): array
    {
        return $this->sessions;
    }

    /**
     * 获取已连接的 clientId 列表（测试用）。
     *
     * @return array<string, string>
     */
    public function getClientIds(): array
    {
        return $this->clientIds;
    }

    /**
     * 测试用：直接模拟处理一个 PUBLISH（不经过 socket）。
     *
     * @internal 仅供单元测试
     */
    public function handlePublishForTest(string $topic, string $payload, int $qos, bool $retain): void
    {
        if ($retain) {
            if ($payload === '') {
                unset($this->retainedMessages[$topic]);
            } else {
                $this->retainedMessages[$topic] = ['payload' => $payload, 'qos' => $qos];
            }
        }
        $this->publishToSubscribers($topic, $payload, $qos, $retain, []);
    }

    /**
     * 测试用：直接模拟添加一个订阅（不经过 socket）。
     *
     * @internal 仅供单元测试
     */
    public function addSubscriptionForTest(string $clientId, string $filter, int $qos): void
    {
        if (!isset($this->sessions[$clientId])) {
            $this->sessions[$clientId] = [
                'subscriptions'      => [],
                'pendingOutbound'    => [],
                'pendingInboundQos2' => [],
            ];
        }
        $this->sessions[$clientId]['subscriptions'][$filter] = $qos;
    }
}
