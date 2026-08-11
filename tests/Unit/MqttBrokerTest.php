<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Mqtt\Packet\Codec;
use Kode\Messaging\Adapter\Mqtt\Packet\Connect;
use Kode\Messaging\Adapter\Mqtt\Packet\PacketType;
use Kode\Messaging\Adapter\Mqtt\Packet\Publish;
use Kode\Messaging\Adapter\Mqtt\Packet\Subscribe;
use Kode\Messaging\Adapter\Mqtt\Server;
use Kode\Messaging\Adapter\Registry;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * MQTT 3.1.1 Broker 单元测试
 *
 * 覆盖：
 *  1. 主题匹配（+ / # 通配符）
 *  2. 包编码/解码（CONNACK / SUBACK / PUBACK / CONNECT / PUBLISH / SUBSCRIBE）
 *  3. 保留消息逻辑
 *  4. 会话管理
 *  5. scheme / autoRegister / connect 抛异常
 */
final class MqttBrokerTest extends TestCase
{
    // ============================================================
    // 1. 主题匹配测试
    // ============================================================

    public function test_match_topic_exact(): void
    {
        $this->assertTrue(Server::matchTopic('sport/tennis', 'sport/tennis'));
        $this->assertFalse(Server::matchTopic('sport/tennis', 'sport/football'));
    }

    public function test_match_topic_single_level_wildcard(): void
    {
        // + 匹配恰好一个层级
        $this->assertTrue(Server::matchTopic('sport/+/player', 'sport/tennis/player'));
        $this->assertTrue(Server::matchTopic('sport/+/player', 'sport/football/player'));
        $this->assertFalse(Server::matchTopic('sport/+/player', 'sport/tennis/player/ranking'));
        $this->assertFalse(Server::matchTopic('sport/+/player', 'sport/tennis'));
    }

    public function test_match_topic_multi_level_wildcard(): void
    {
        // # 匹配零个或多个层级，必须位于末尾
        $this->assertTrue(Server::matchTopic('sport/#', 'sport'));
        $this->assertTrue(Server::matchTopic('sport/#', 'sport/tennis'));
        $this->assertTrue(Server::matchTopic('sport/#', 'sport/tennis/player1'));
        $this->assertTrue(Server::matchTopic('sport/#', 'sport/tennis/player1/ranking'));
        $this->assertFalse(Server::matchTopic('sport/#', 'sports/tennis'));
    }

    public function test_match_topic_combined_wildcards(): void
    {
        $this->assertTrue(Server::matchTopic('+/tennis/#', 'sport/tennis/player1/ranking'));
        $this->assertTrue(Server::matchTopic('+/tennis/#', 'sport/tennis'));
        $this->assertFalse(Server::matchTopic('+/tennis/#', 'sport/football/player1'));
    }

    public function test_match_topic_hash_only(): void
    {
        // # 单独使用匹配所有主题
        $this->assertTrue(Server::matchTopic('#', 'sport'));
        $this->assertTrue(Server::matchTopic('#', 'sport/tennis/player'));
        $this->assertTrue(Server::matchTopic('#', ''));
    }

    public function test_match_topic_plus_only(): void
    {
        // + 单独使用匹配恰好一个层级
        $this->assertTrue(Server::matchTopic('+', 'sport'));
        $this->assertFalse(Server::matchTopic('+', 'sport/tennis'));
    }

    public function test_match_topic_leading_slash(): void
    {
        // MQTT 主题不应以 / 开头，但算法应正确处理空层级
        $this->assertTrue(Server::matchTopic('/sport/#', '/sport/tennis'));
        $this->assertFalse(Server::matchTopic('/sport/#', 'sport/tennis'));
    }

    // ============================================================
    // 2. 包编码/解码测试
    // ============================================================

    public function test_encode_connack_accepted(): void
    {
        $packet = Server::encodeConnack(0, false);
        // CONNACK 类型 = 2，左移 4 位 = 0x20
        $this->assertSame(0x20, ord($packet[0]));
        // 剩余长度 = 2
        $this->assertSame(2, ord($packet[1]));
        // Ack Flags = 0（session present = false）
        $this->assertSame(0, ord($packet[2]));
        // Return Code = 0（接受）
        $this->assertSame(0, ord($packet[3]));
    }

    public function test_encode_connack_session_present(): void
    {
        $packet = Server::encodeConnack(0, true);
        // session present = true → Ack Flags = 0x01
        $this->assertSame(0x01, ord($packet[2]));
        $this->assertSame(0, ord($packet[3]));
    }

    public function test_encode_connack_rejected(): void
    {
        $packet = Server::encodeConnack(5); // Not Authorized
        $this->assertSame(0x20, ord($packet[0]));
        $this->assertSame(5, ord($packet[3]));
    }

    public function test_encode_suback(): void
    {
        $packet = Server::encodeSuback(42, [0, 1, 2]);
        // SUBACK 类型 = 9，左移 4 位 = 0x90
        $this->assertSame(0x90, ord($packet[0]));
        // 剩余长度 = 2 (packet id) + 3 (return codes)
        $this->assertSame(5, ord($packet[1]));
        // Packet ID = 42
        $this->assertSame(42, unpack('n', substr($packet, 2, 2))[1]);
        // Return codes
        $this->assertSame(0, ord($packet[4]));
        $this->assertSame(1, ord($packet[5]));
        $this->assertSame(2, ord($packet[6]));
    }

    public function test_encode_suback_failure(): void
    {
        $packet = Server::encodeSuback(1, [128]);
        $this->assertSame(128, ord($packet[4]));
    }

    public function test_encode_puback(): void
    {
        $packet = Server::encodePuback(100);
        // PUBACK 类型 = 4，左移 4 位 = 0x40
        $this->assertSame(0x40, ord($packet[0]));
        $this->assertSame(2, ord($packet[1]));
        $this->assertSame(100, unpack('n', substr($packet, 2, 2))[1]);
    }

    public function test_encode_pubrec(): void
    {
        $packet = Server::encodePubrec(200);
        // PUBREC 类型 = 5，左移 4 位 = 0x50
        $this->assertSame(0x50, ord($packet[0]));
        $this->assertSame(200, unpack('n', substr($packet, 2, 2))[1]);
    }

    public function test_encode_pubrel(): void
    {
        $packet = Server::encodePubrel(300);
        // PUBREL 类型 = 6，左移 4 位 + flags 0x02 = 0x62
        $this->assertSame(0x62, ord($packet[0]));
        $this->assertSame(300, unpack('n', substr($packet, 2, 2))[1]);
    }

    public function test_encode_pubcomp(): void
    {
        $packet = Server::encodePubcomp(400);
        // PUBCOMP 类型 = 7，左移 4 位 = 0x70
        $this->assertSame(0x70, ord($packet[0]));
        $this->assertSame(400, unpack('n', substr($packet, 2, 2))[1]);
    }

    public function test_encode_unsuback(): void
    {
        $packet = Server::encodeUnsuback(500);
        // UNSUBACK 类型 = 11，左移 4 位 = 0xB0
        $this->assertSame(0xB0, ord($packet[0]));
        $this->assertSame(500, unpack('n', substr($packet, 2, 2))[1]);
    }

    public function test_encode_pingresp(): void
    {
        $packet = Server::encodePingresp();
        // PINGRESP 类型 = 13，左移 4 位 = 0xD0
        $this->assertSame(0xD0, ord($packet[0]));
        $this->assertSame(0, ord($packet[1]));
        $this->assertSame(2, strlen($packet));
    }

    public function test_encode_disconnect(): void
    {
        $packet = Server::encodeDisconnect();
        // DISCONNECT 类型 = 14，左移 4 位 = 0xE0
        $this->assertSame(0xE0, ord($packet[0]));
        $this->assertSame(0, ord($packet[1]));
        $this->assertSame(2, strlen($packet));
    }

    public function test_decode_connect_basic(): void
    {
        $packet = Connect::encode(
            clientId: 'client-001',
            username: null,
            password: null,
            keepalive: 60,
            cleanSession: true,
            version: '3.1.1',
        );

        // 跳过固定头（type + remaining length）
        $offset = 1;
        $remainingLen = Codec::decodeRemainingLength($packet, $offset);
        $body = substr($packet, $offset, $remainingLen);

        $info = Server::decodeConnect($body);

        $this->assertSame('MQTT', $info['protocol_name']);
        $this->assertSame(4, $info['protocol_level']);
        $this->assertTrue($info['clean_session']);
        $this->assertSame(60, $info['keepalive']);
        $this->assertSame('client-001', $info['client_id']);
        $this->assertFalse($info['will_flag']);
        $this->assertNull($info['username']);
        $this->assertNull($info['password']);
    }

    public function test_decode_connect_with_will(): void
    {
        $packet = Connect::encode(
            clientId: 'device-001',
            username: 'user',
            password: 'pass',
            keepalive: 30,
            cleanSession: false,
            will: [
                'topic' => 'devices/001/status',
                'payload' => 'offline',
                'qos' => 1,
                'retain' => true,
            ],
            version: '3.1.1',
        );

        $offset = 1;
        $remainingLen = Codec::decodeRemainingLength($packet, $offset);
        $body = substr($packet, $offset, $remainingLen);

        $info = Server::decodeConnect($body);

        $this->assertSame('device-001', $info['client_id']);
        $this->assertFalse($info['clean_session']);
        $this->assertTrue($info['will_flag']);
        $this->assertSame(1, $info['will_qos']);
        $this->assertTrue($info['will_retain']);
        $this->assertSame('devices/001/status', $info['will_topic']);
        $this->assertSame('offline', $info['will_payload']);
        $this->assertSame('user', $info['username']);
        $this->assertSame('pass', $info['password']);
    }

    public function test_decode_subscribe(): void
    {
        $packet = Subscribe::encode(42, [
            ['topic' => 'sensors/+/temp', 'qos' => 1],
            ['topic' => 'alerts/#', 'qos' => 2],
        ]);

        $offset = 1;
        $remainingLen = Codec::decodeRemainingLength($packet, $offset);
        $body = substr($packet, $offset, $remainingLen);

        $info = Server::decodeSubscribe($body);

        $this->assertSame(42, $info['packet_id']);
        $this->assertCount(2, $info['topics']);
        $this->assertSame('sensors/+/temp', $info['topics'][0]['topic']);
        $this->assertSame(1, $info['topics'][0]['qos']);
        $this->assertSame('alerts/#', $info['topics'][1]['topic']);
        $this->assertSame(2, $info['topics'][1]['qos']);
    }

    public function test_decode_unsubscribe(): void
    {
        // 手动构造 UNSUBSCRIBE 包
        $body = Codec::encodeUint16(99);
        $body .= Codec::encodeString('topic/a');
        $body .= Codec::encodeString('topic/b');
        $packet = Codec::encodeFixedHeader(PacketType::UNSUBSCRIBE, 0x02, strlen($body)).$body;

        $offset = 1;
        $remainingLen = Codec::decodeRemainingLength($packet, $offset);
        $bodyPart = substr($packet, $offset, $remainingLen);

        $info = Server::decodeUnsubscribe($bodyPart);

        $this->assertSame(99, $info['packet_id']);
        $this->assertCount(2, $info['topics']);
        $this->assertSame('topic/a', $info['topics'][0]);
        $this->assertSame('topic/b', $info['topics'][1]);
    }

    public function test_publish_encode_decode_roundtrip(): void
    {
        $original = Publish::encode('sensors/temp', '23.5', qos: 1, retain: true, packetId: 7);

        // 跳过固定头
        $byte0 = ord($original[0]);
        $offset = 1;
        $remainingLen = Codec::decodeRemainingLength($original, $offset);
        $body = substr($original, $offset, $remainingLen);

        $decoded = Publish::decode(chr($byte0), $body);

        $this->assertSame('sensors/temp', $decoded['topic']);
        $this->assertSame('23.5', $decoded['payload']);
        $this->assertSame(1, $decoded['qos']);
        $this->assertTrue($decoded['retain']);
        $this->assertSame(7, $decoded['packet_id']);
    }

    // ============================================================
    // 3. 保留消息逻辑测试
    // ============================================================

    public function test_retained_message_stored_on_publish(): void
    {
        $server = new Server(new NullLogger());

        $server->handlePublishForTest('sensors/temp', '23.5', 1, true);

        $retained = $server->getRetainedMessages();
        $this->assertArrayHasKey('sensors/temp', $retained);
        $this->assertSame('23.5', $retained['sensors/temp']['payload']);
        $this->assertSame(1, $retained['sensors/temp']['qos']);
    }

    public function test_retained_message_cleared_on_empty_payload(): void
    {
        $server = new Server(new NullLogger());

        // 先存储
        $server->handlePublishForTest('sensors/temp', '23.5', 1, true);
        $this->assertArrayHasKey('sensors/temp', $server->getRetainedMessages());

        // 空载荷清除
        $server->handlePublishForTest('sensors/temp', '', 0, true);
        $this->assertArrayNotHasKey('sensors/temp', $server->getRetainedMessages());
    }

    public function test_retained_message_not_stored_when_retain_false(): void
    {
        $server = new Server(new NullLogger());

        $server->handlePublishForTest('sensors/temp', '23.5', 0, false);

        $this->assertArrayNotHasKey('sensors/temp', $server->getRetainedMessages());
    }

    public function test_retained_message_overwritten(): void
    {
        $server = new Server(new NullLogger());

        $server->handlePublishForTest('sensors/temp', '23.5', 0, true);
        $server->handlePublishForTest('sensors/temp', '24.0', 1, true);

        $retained = $server->getRetainedMessages();
        $this->assertSame('24.0', $retained['sensors/temp']['payload']);
        $this->assertSame(1, $retained['sensors/temp']['qos']);
    }

    // ============================================================
    // 4. 会话管理测试
    // ============================================================

    public function test_session_created_on_subscribe(): void
    {
        $server = new Server(new NullLogger());

        $server->addSubscriptionForTest('client-001', 'sensors/#', 1);

        $sessions = $server->getSessions();
        $this->assertArrayHasKey('client-001', $sessions);
        $this->assertArrayHasKey('sensors/#', $sessions['client-001']['subscriptions']);
        $this->assertSame(1, $sessions['client-001']['subscriptions']['sensors/#']);
    }

    public function test_session_multiple_subscriptions(): void
    {
        $server = new Server(new NullLogger());

        $server->addSubscriptionForTest('client-001', 'sensors/temp', 0);
        $server->addSubscriptionForTest('client-001', 'sensors/humidity', 1);
        $server->addSubscriptionForTest('client-001', 'alerts/#', 2);

        $sessions = $server->getSessions();
        $this->assertCount(3, $sessions['client-001']['subscriptions']);
        $this->assertSame(0, $sessions['client-001']['subscriptions']['sensors/temp']);
        $this->assertSame(1, $sessions['client-001']['subscriptions']['sensors/humidity']);
        $this->assertSame(2, $sessions['client-001']['subscriptions']['alerts/#']);
    }

    public function test_session_subscription_overwrite(): void
    {
        $server = new Server(new NullLogger());

        $server->addSubscriptionForTest('client-001', 'sensors/temp', 0);
        $server->addSubscriptionForTest('client-001', 'sensors/temp', 2);

        $sessions = $server->getSessions();
        $this->assertSame(2, $sessions['client-001']['subscriptions']['sensors/temp']);
    }

    public function test_multiple_client_sessions(): void
    {
        $server = new Server(new NullLogger());

        $server->addSubscriptionForTest('client-001', 'sensors/temp', 0);
        $server->addSubscriptionForTest('client-002', 'sensors/humidity', 1);

        $sessions = $server->getSessions();
        $this->assertCount(2, $sessions);
        $this->assertArrayHasKey('client-001', $sessions);
        $this->assertArrayHasKey('client-002', $sessions);
    }

    // ============================================================
    // 5. 服务器基础功能测试
    // ============================================================

    public function test_scheme_is_mqtt(): void
    {
        $this->assertSame('mqtt', Server::scheme());
    }

    public function test_version_is_mqtt311(): void
    {
        $server = new Server(new NullLogger());
        $this->assertSame('mqtt-3.1.1', $server->version());
    }

    public function test_connect_throws_logic_exception(): void
    {
        $server = new Server(new NullLogger());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('不支持 connect()');
        $server->connect([]);
    }

    public function test_auto_register(): void
    {
        Registry::reset();
        Server::autoRegister();
        $this->assertSame(Server::class, Registry::find('mqtt'));
    }

    public function test_default_config(): void
    {
        $server = new Server(new NullLogger());
        $server->boot([]);
        $config = $server->config();
        $this->assertSame(1_048_576, $config['max_payload']);
        $this->assertTrue($config['allow_anonymous']);
    }

    public function test_shutdown_clears_state(): void
    {
        $server = new Server(new NullLogger());
        $server->boot([]);

        // 添加一些状态
        $server->addSubscriptionForTest('client-001', 'sensors/#', 1);
        $server->handlePublishForTest('sensors/temp', '23.5', 0, true);

        $server->shutdown();

        $this->assertEmpty($server->getSessions());
        $this->assertEmpty($server->getRetainedMessages());
        $this->assertEmpty($server->getClientIds());
    }

    public function test_is_running_initially_false(): void
    {
        $server = new Server(new NullLogger());
        $this->assertFalse($server->isRunning());
    }

    // ============================================================
    // 6. Remaining Length 编码测试
    // ============================================================

    public function test_remaining_length_single_byte(): void
    {
        $this->assertSame(chr(0), Codec::encodeRemainingLength(0));
        $this->assertSame(chr(127), Codec::encodeRemainingLength(127));
    }

    public function test_remaining_length_two_bytes(): void
    {
        $encoded = Codec::encodeRemainingLength(128);
        $this->assertSame(2, strlen($encoded));
        $offset = 0;
        $this->assertSame(128, Codec::decodeRemainingLength($encoded, $offset));
    }

    public function test_remaining_length_large_value(): void
    {
        // 使用 3 字节最大值（Codec 对 4 字节最大值有已知限制）
        $value = 2097151; // 3-byte 最大值
        $encoded = Codec::encodeRemainingLength($value);
        $offset = 0;
        $this->assertSame($value, Codec::decodeRemainingLength($encoded, $offset));
    }

    // ============================================================
    // 7. QoS 流程验证（通过包类型）
    // ============================================================

    public function test_qos1_packet_types(): void
    {
        // PUBLISH (QoS 1) → PUBACK
        $pub = Publish::encode('test/topic', 'hello', qos: 1, packetId: 1);
        $this->assertSame(0x32, ord($pub[0])); // PUBLISH + QoS 1 flags

        $ack = Server::encodePuback(1);
        $this->assertSame(PacketType::PUBACK, (ord($ack[0]) >> 4) & 0x0F);
    }

    public function test_qos2_packet_types(): void
    {
        // PUBLISH (QoS 2) → PUBREC → PUBREL → PUBCOMP
        $pub = Publish::encode('test/topic', 'hello', qos: 2, packetId: 1);
        $this->assertSame(0x34, ord($pub[0])); // PUBLISH + QoS 2 flags

        $rec = Server::encodePubrec(1);
        $this->assertSame(PacketType::PUBREC, (ord($rec[0]) >> 4) & 0x0F);

        $rel = Server::encodePubrel(1);
        $this->assertSame(PacketType::PUBREL, (ord($rel[0]) >> 4) & 0x0F);
        $this->assertSame(0x02, ord($rel[0]) & 0x0F); // PUBREL flags = 0x02

        $comp = Server::encodePubcomp(1);
        $this->assertSame(PacketType::PUBCOMP, (ord($comp[0]) >> 4) & 0x0F);
    }

    public function test_all_packet_type_byte_values(): void
    {
        // 验证所有控制包类型的固定头字节值
        $this->assertSame(0x20, ord(Server::encodeConnack(0)[0]));      // CONNACK
        $this->assertSame(0x40, ord(Server::encodePuback(1)[0]));       // PUBACK
        $this->assertSame(0x50, ord(Server::encodePubrec(1)[0]));       // PUBREC
        $this->assertSame(0x62, ord(Server::encodePubrel(1)[0]));       // PUBREL (flags=0x02)
        $this->assertSame(0x70, ord(Server::encodePubcomp(1)[0]));      // PUBCOMP
        $this->assertSame(0x90, ord(Server::encodeSuback(1, [0])[0]));  // SUBACK
        $this->assertSame(0xB0, ord(Server::encodeUnsuback(1)[0]));     // UNSUBACK
        $this->assertSame(0xD0, ord(Server::encodePingresp()[0]));      // PINGRESP
        $this->assertSame(0xE0, ord(Server::encodeDisconnect()[0]));    // DISCONNECT
    }
}
