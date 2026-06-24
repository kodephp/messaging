<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Mqtt\Packet\Connect;
use Kode\Messaging\Adapter\Mqtt\Packet\PacketType;
use Kode\Messaging\Adapter\Mqtt\Packet\Properties;
use Kode\Messaging\Adapter\Mqtt\Packet\ReasonCode;
use Kode\Messaging\Adapter\Mqtt\Server;
use PHPUnit\Framework\TestCase;

/**
 * MQTT 5.0 协议单元测试
 *
 * 覆盖：
 *  1. Properties 编解码（各种数据类型）
 *  2. ReasonCode 常量与工具方法
 *  3. CONNECT 5.0 编解码（含 properties + will properties）
 *  4. CONNACK 5.0 编码
 *  5. Server 接受 MQTT 5.0（level 5）
 *  6. Server 拒绝不支持的版本
 */
final class MqttV5Test extends TestCase
{
    // ===================== Properties 编解码 =====================

    public function testPropertiesEncodeEmpty(): void
    {
        $encoded = Properties::encode([]);
        // 空属性 = 剩余长度 0 = 1 字节 0x00
        $this->assertSame("\x00", $encoded);
    }

    public function testPropertiesEncodeDecodeByte(): void
    {
        $props = [Properties::PAYLOAD_FORMAT_INDICATOR => 1];
        $encoded = Properties::encode($props);
        $offset = 0;
        $decoded = Properties::decode($encoded, $offset);
        $this->assertSame(1, $decoded[Properties::PAYLOAD_FORMAT_INDICATOR]);
    }

    public function testPropertiesEncodeDecodeUint16(): void
    {
        $props = [Properties::RECEIVE_MAXIMUM => 65535];
        $encoded = Properties::encode($props);
        $offset = 0;
        $decoded = Properties::decode($encoded, $offset);
        $this->assertSame(65535, $decoded[Properties::RECEIVE_MAXIMUM]);
    }

    public function testPropertiesEncodeDecodeUint32(): void
    {
        $props = [Properties::SESSION_EXPIRY_INTERVAL => 3600];
        $encoded = Properties::encode($props);
        $offset = 0;
        $decoded = Properties::decode($encoded, $offset);
        $this->assertSame(3600, $decoded[Properties::SESSION_EXPIRY_INTERVAL]);
    }

    public function testPropertiesEncodeDecodeString(): void
    {
        $props = [Properties::CONTENT_TYPE => 'application/json'];
        $encoded = Properties::encode($props);
        $offset = 0;
        $decoded = Properties::decode($encoded, $offset);
        $this->assertSame('application/json', $decoded[Properties::CONTENT_TYPE]);
    }

    public function testPropertiesEncodeDecodeBinary(): void
    {
        $props = [Properties::CORRELATION_DATA => "\x01\x02\x03"];
        $encoded = Properties::encode($props);
        $offset = 0;
        $decoded = Properties::decode($encoded, $offset);
        $this->assertSame("\x01\x02\x03", $decoded[Properties::CORRELATION_DATA]);
    }

    public function testPropertiesEncodeDecodeVarInt(): void
    {
        $props = [Properties::SUBSCRIPTION_IDENTIFIER => [1, 2, 3]];
        $encoded = Properties::encode($props);
        $offset = 0;
        $decoded = Properties::decode($encoded, $offset);
        $this->assertSame([1, 2, 3], $decoded[Properties::SUBSCRIPTION_IDENTIFIER]);
    }

    public function testPropertiesEncodeDecodeUserProperty(): void
    {
        $props = [Properties::USER_PROPERTY => [['key1', 'val1'], ['key2', 'val2']]];
        $encoded = Properties::encode($props);
        $offset = 0;
        $decoded = Properties::decode($encoded, $offset);
        $this->assertSame([['key1', 'val1'], ['key2', 'val2']], $decoded[Properties::USER_PROPERTY]);
    }

    public function testPropertiesEncodeDecodeMultiple(): void
    {
        $props = [
            Properties::SESSION_EXPIRY_INTERVAL  => 600,
            Properties::RECEIVE_MAXIMUM          => 100,
            Properties::MAXIMUM_PACKET_SIZE      => 268435455,
            Properties::REASON_STRING            => 'test reason',
        ];
        $encoded = Properties::encode($props);
        $offset = 0;
        $decoded = Properties::decode($encoded, $offset);
        $this->assertSame(600, $decoded[Properties::SESSION_EXPIRY_INTERVAL]);
        $this->assertSame(100, $decoded[Properties::RECEIVE_MAXIMUM]);
        $this->assertSame(268435455, $decoded[Properties::MAXIMUM_PACKET_SIZE]);
        $this->assertSame('test reason', $decoded[Properties::REASON_STRING]);
    }

    // ===================== ReasonCode =====================

    public function testReasonCodeIsSuccess(): void
    {
        $this->assertTrue(ReasonCode::isSuccess(ReasonCode::SUCCESS));
        $this->assertTrue(ReasonCode::isSuccess(ReasonCode::GRANTED_QOS_1));
        $this->assertFalse(ReasonCode::isSuccess(ReasonCode::NOT_AUTHORIZED));
        $this->assertFalse(ReasonCode::isSuccess(ReasonCode::UNSUPPORTED_PROTOCOL_VERSION));
    }

    public function testReasonCodeIsError(): void
    {
        $this->assertFalse(ReasonCode::isError(ReasonCode::SUCCESS));
        $this->assertTrue(ReasonCode::isError(ReasonCode::MALFORMED_PACKET));
        $this->assertTrue(ReasonCode::isError(ReasonCode::QUOTA_EXCEEDED));
    }

    public function testReasonCodeDescription(): void
    {
        $this->assertSame('Success', ReasonCode::description(ReasonCode::SUCCESS));
        $this->assertSame('Not authorized', ReasonCode::description(ReasonCode::NOT_AUTHORIZED));
        $this->assertSame('Malformed packet', ReasonCode::description(ReasonCode::MALFORMED_PACKET));
        $this->assertStringStartsWith('Unknown', ReasonCode::description(0xFF));
    }

    // ===================== CONNECT 5.0 编解码 =====================

    public function testConnectEncodeV5ProtocolLevel(): void
    {
        $packet = Connect::encode('client-1', version: '5.0');
        // 解析固定头
        $byte0 = ord($packet[0]);
        $type = ($byte0 >> 4) & 0x0F;
        $this->assertSame(PacketType::CONNECT, $type);

        // 跳过固定头，解析变长头
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = substr($packet, $offset, $remainingLen);
        $info = Connect::decode($body);

        $this->assertSame(5, $info['protocol_level']);
        $this->assertSame('5.0', $info['version']);
    }

    public function testConnectEncodeV5WithProperties(): void
    {
        $properties = [
            Properties::SESSION_EXPIRY_INTERVAL => 300,
            Properties::RECEIVE_MAXIMUM         => 50,
        ];
        $packet = Connect::encode(
            'client-5',
            version: '5.0',
            properties: $properties,
        );

        // 解码验证
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = substr($packet, $offset, $remainingLen);
        $info = Connect::decode($body);

        $this->assertSame('5.0', $info['version']);
        $this->assertSame(300, $info['properties'][Properties::SESSION_EXPIRY_INTERVAL]);
        $this->assertSame(50, $info['properties'][Properties::RECEIVE_MAXIMUM]);
    }

    public function testConnectEncodeV5WithWillProperties(): void
    {
        $will = [
            'topic'      => 'will/topic',
            'payload'    => 'bye',
            'qos'        => 1,
            'retain'     => false,
            'properties' => [Properties::MESSAGE_EXPIRY_INTERVAL => 60],
        ];
        $packet = Connect::encode(
            'client-will',
            will: $will,
            version: '5.0',
        );

        // 解码验证
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = substr($packet, $offset, $remainingLen);
        $info = Connect::decode($body);

        $this->assertTrue($info['will_flag']);
        $this->assertSame('will/topic', $info['will_topic']);
        $this->assertSame(60, $info['will_properties'][Properties::MESSAGE_EXPIRY_INTERVAL]);
    }

    public function testConnectEncodeV311NoProperties(): void
    {
        $packet = Connect::encode('client-311', version: '3.1.1');
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = substr($packet, $offset, $remainingLen);
        $info = Connect::decode($body);

        $this->assertSame(4, $info['protocol_level']);
        $this->assertSame('3.1.1', $info['version']);
        $this->assertSame([], $info['properties']);
        $this->assertSame([], $info['will_properties']);
    }

    // ===================== CONNACK 5.0 编码 =====================

    public function testEncodeConnackV5Success(): void
    {
        $packet = Server::encodeConnackV5(ReasonCode::SUCCESS, false);
        $byte0 = ord($packet[0]);
        $type = ($byte0 >> 4) & 0x0F;
        $this->assertSame(PacketType::CONNACK, $type);

        // 解析 body
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = substr($packet, $offset, $remainingLen);
        $ackFlags = ord($body[0]);
        $reasonCode = ord($body[1]);
        $this->assertSame(0x00, $ackFlags); // session present = false
        $this->assertSame(ReasonCode::SUCCESS, $reasonCode);
    }

    public function testEncodeConnackV5WithProperties(): void
    {
        $props = [
            Properties::ASSIGNED_CLIENT_IDENTIFIER => 'auto-abc123',
            Properties::MAXIMUM_QOS                => 1,
        ];
        $packet = Server::encodeConnackV5(ReasonCode::SUCCESS, true, $props);

        // 解析 body
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = substr($packet, $offset, $remainingLen);
        $bodyOffset = 0;
        $ackFlags = ord($body[$bodyOffset++]);
        $reasonCode = ord($body[$bodyOffset++]);
        $decodedProps = Properties::decode($body, $bodyOffset);

        $this->assertTrue(($ackFlags & 0x01) !== 0); // session present
        $this->assertSame(ReasonCode::SUCCESS, $reasonCode);
        $this->assertSame('auto-abc123', $decodedProps[Properties::ASSIGNED_CLIENT_IDENTIFIER]);
        $this->assertSame(1, $decodedProps[Properties::MAXIMUM_QOS]);
    }

    public function testEncodeConnackV5Reject(): void
    {
        $packet = Server::encodeConnackV5(ReasonCode::UNSUPPORTED_PROTOCOL_VERSION);
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = substr($packet, $offset, $remainingLen);
        $reasonCode = ord($body[1]);
        $this->assertSame(ReasonCode::UNSUPPORTED_PROTOCOL_VERSION, $reasonCode);
    }

    // ===================== DISCONNECT 5.0 编码 =====================

    public function testEncodeDisconnectV5(): void
    {
        $packet = Server::encodeDisconnectV5(ReasonCode::SERVER_BUSY);
        $byte0 = ord($packet[0]);
        $type = ($byte0 >> 4) & 0x0F;
        $this->assertSame(PacketType::DISCONNECT, $type);

        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = substr($packet, $offset, $remainingLen);
        $reasonCode = ord($body[0]);
        $this->assertSame(ReasonCode::SERVER_BUSY, $reasonCode);
    }

    // ===================== PUBACK / PUBREC / PUBREL / PUBCOMP 5.0 =====================

    public function testEncodePubackV5(): void
    {
        $packet = Server::encodePubackV5(42, ReasonCode::SUCCESS);
        $byte0 = ord($packet[0]);
        $type = ($byte0 >> 4) & 0x0F;
        $this->assertSame(PacketType::PUBACK, $type);

        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = substr($packet, $offset, $remainingLen);
        $packetId = unpack('n', substr($body, 0, 2))[1];
        $reasonCode = ord($body[2]);
        $this->assertSame(42, $packetId);
        $this->assertSame(ReasonCode::SUCCESS, $reasonCode);
    }

    public function testEncodePubrecV5(): void
    {
        $packet = Server::encodePubrecV5(99, ReasonCode::SUCCESS);
        $byte0 = ord($packet[0]);
        $type = ($byte0 >> 4) & 0x0F;
        $this->assertSame(PacketType::PUBREC, $type);
    }

    public function testEncodePubrelV5(): void
    {
        $packet = Server::encodePubrelV5(7, ReasonCode::SUCCESS);
        $byte0 = ord($packet[0]);
        $type = ($byte0 >> 4) & 0x0F;
        $this->assertSame(PacketType::PUBREL, $type);
    }

    public function testEncodePubcompV5(): void
    {
        $packet = Server::encodePubcompV5(7, ReasonCode::SUCCESS);
        $byte0 = ord($packet[0]);
        $type = ($byte0 >> 4) & 0x0F;
        $this->assertSame(PacketType::PUBCOMP, $type);
    }

    // ===================== Server 接受 5.0 =====================

    public function testServerAcceptsV5Connect(): void
    {
        // 构造一个 MQTT 5.0 CONNECT 包
        $connectPacket = Connect::encode(
            'test-v5-client',
            version: '5.0',
            properties: [Properties::SESSION_EXPIRY_INTERVAL => 600],
        );

        // 跳过固定头，提取 body
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($connectPacket[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);
        $body = substr($connectPacket, $offset, $remainingLen);

        // 解码
        $info = Connect::decode($body);
        $this->assertSame(5, $info['protocol_level']);
        $this->assertSame('5.0', $info['version']);
        $this->assertSame('test-v5-client', $info['client_id']);
        $this->assertSame(600, $info['properties'][Properties::SESSION_EXPIRY_INTERVAL]);
    }

    public function testServerAcceptsV311Connect(): void
    {
        $connectPacket = Connect::encode('test-v311-client', version: '3.1.1');
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($connectPacket[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);
        $body = substr($connectPacket, $offset, $remainingLen);

        $info = Connect::decode($body);
        $this->assertSame(4, $info['protocol_level']);
        $this->assertSame('3.1.1', $info['version']);
        $this->assertSame([], $info['properties']);
    }

    // ===================== 往返测试 =====================

    public function testConnectDecodeEncodeRoundTripV5(): void
    {
        $original = [
            'clientId'    => 'round-trip-5',
            'username'    => 'user1',
            'password'    => 'pass1',
            'keepalive'   => 120,
            'cleanSession'=> false,
            'will'        => [
                'topic'   => 'last/will',
                'payload' => 'goodbye',
                'qos'     => 1,
                'retain'  => true,
            ],
            'version'     => '5.0',
            'properties'  => [
                Properties::SESSION_EXPIRY_INTERVAL => 1800,
                Properties::RECEIVE_MAXIMUM         => 200,
            ],
        ];

        $packet = Connect::encode(
            $original['clientId'],
            $original['username'],
            $original['password'],
            $original['keepalive'],
            $original['cleanSession'],
            $original['will'],
            $original['version'],
            $original['properties'],
        );

        // 跳过固定头
        $offset = 1;
        $remainingLen = 0;
        $multiplier = 1;
        do {
            $byte = ord($packet[$offset++]);
            $remainingLen += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);
        $body = substr($packet, $offset, $remainingLen);

        $decoded = Connect::decode($body);

        $this->assertSame($original['clientId'], $decoded['client_id']);
        $this->assertSame($original['username'], $decoded['username']);
        $this->assertSame($original['password'], $decoded['password']);
        $this->assertSame($original['keepalive'], $decoded['keepalive']);
        $this->assertSame($original['cleanSession'], $decoded['clean_session']);
        $this->assertTrue($decoded['will_flag']);
        $this->assertSame($original['will']['topic'], $decoded['will_topic']);
        $this->assertSame($original['will']['payload'], $decoded['will_payload']);
        $this->assertSame($original['will']['qos'], $decoded['will_qos']);
        $this->assertTrue($decoded['will_retain']);
        $this->assertSame('5.0', $decoded['version']);
        $this->assertSame(1800, $decoded['properties'][Properties::SESSION_EXPIRY_INTERVAL]);
        $this->assertSame(200, $decoded['properties'][Properties::RECEIVE_MAXIMUM]);
    }
}
