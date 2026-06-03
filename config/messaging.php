<?php
/**
 * kode/messaging 默认配置
 *
 * 通过 Messaging::configure() 加载后，Kode\Messaging\Messaging 全局可用。
 * 业务代码可在调用 server()/client() 时按需覆盖。
 */

declare(strict_types=1);

return [
    // 默认协议（scheme）：ws / sse / mqtt / udp
    'default' => 'ws',

    // 全局组件
    'logger' => null,                          // Psr\Log\LoggerInterface|null
    'event_dispatcher' => null,                // Psr\EventDispatcher\EventDispatcherInterface|null

    // 传输层选择：auto | stream | sockets | swoole | swow
    //   - auto    : 自动检测最佳传输层
    //   - stream  : 纯 PHP stream_socket_*，零扩展依赖
    //   - sockets : ext-sockets
    //   - swoole  : ext-swoole（协程）
    //   - swow    : ext-swow（协程）
    'transport' => 'auto',

    // ============== WebSocket ==============
    'websocket' => [
        'host'             => '0.0.0.0',
        'port'             => 8080,
        'max_frame_size'   => 1_048_576,       // 1 MiB
        'max_connections'  => 10_000,
        'allowed_origins'  => ['*'],          // 生产必须明确指定
        'heartbeat_interval' => 30,            // 秒
        'heartbeat_timeout'  => 60,
        'handshake_timeout'  => 10,
        'subprotocols'    => [],               // 支持的子协议
        'enable_compression' => false,         // permessage-deflate
        'enable_binary'   => true,
    ],

    // ============== SSE ==============
    'sse' => [
        'host'             => '0.0.0.0',
        'port'             => 8081,
        'retry_ms'         => 3000,            // 客户端断线重试间隔
        'keepalive_seconds' => 15,
        'max_connections'  => 10_000,
        'heartbeat_event'  => 'ping',
        'enable_cors'      => true,
    ],

    // ============== MQTT ==============
    'mqtt' => [
        'host'              => '127.0.0.1',
        'port'              => 1883,
        'version'           => '3.1.1',        // '3.1.1' | '5.0'
        'keepalive'         => 60,
        'clean_session'     => true,
        'max_inflight'      => 1000,
        'max_packet_size'   => 268_435_456,    // 256 MiB
        'auto_reconnect'    => true,
        'session' => [
            'driver' => 'memory',              // memory | redis | apcu
            'config' => [],
        ],
        'tls' => [
            'cafile'      => null,
            'local_cert'  => null,
            'local_pk'    => null,
            'verify_peer' => true,
        ],
    ],

    // ============== UDP ==============
    'udp' => [
        'host'             => '0.0.0.0',
        'port'             => 8082,
        'max_packet_size'  => 65_507,          // UDP 单包最大载荷
        'enable_broadcast' => true,
        'enable_multicast' => true,
        'socket_timeout'   => 30,              // 秒，0=阻塞
    ],

    // ============== Long-Polling ==============
    'long-polling' => [
        'host'             => '0.0.0.0',
        'port'             => 8083,
        'max_connections'  => 10_000,
        'hold_timeout_ms'  => 25_000,          // 单次 hold 最长
        'read_timeout'     => 30,
        'max_body_size'    => 1_048_576,       // 1 MiB
        'cors'             => true,
        'allowed_origins'  => ['*'],
        'ping'             => true,            // GET /ping 探活
    ],

    // ============== CoAP（IoT，UDP）==============
    'coap' => [
        'host'               => '0.0.0.0',
        'port'               => 5683,
        'max_packet_size'    => 1_152,          // RFC 7252 链路 MTU 建议
        'ack_timeout_ms'     => 2_000,          // CON 超时
        'max_retransmit'     => 4,
        'retransmit_backoff' => 2.0,
        'enable_observe'     => true,           // RFC 7641
        'default_response_format' => 50,        // application/json
    ],

    // ============== 发布订阅总线 ==============
    'pubsub' => [
        'default' => 'memory',                 // memory | channel | redis
        'redis' => [
            'host'   => '127.0.0.1',
            'port'   => 6379,
            'db'     => 0,
            'prefix' => 'messaging:',
        ],
        'channel' => [
            'driver' => 'kode-process',        // 依赖 kode/process
        ],
    ],

    // ============== 集群 ==============
    'cluster' => [
        'enabled'   => false,
        'driver'    => 'redis',                // redis | channel
        'node_id'   => null,                   // 自动生成
        'heartbeat' => 5,                      // 秒
    ],

    // ============== 性能调优 ==============
    'tuning' => [
        'worker_count'      => 1,
        'use_fibers'        => true,
        'send_buffer_size'  => 65_536,
        'read_buffer_size'  => 65_536,
        'max_outbound_queue' => 10_000,
    ],
];
