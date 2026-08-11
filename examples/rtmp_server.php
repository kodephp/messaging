<?php declare(strict_types=1);

/**
 * RTMP 服务端示例（含限流）
 *
 * 启动后，OBS / FMLE / ffmpeg 可推流到 rtmp://0.0.0.0:1935/live/<key>。
 *
 * 适用：把 RTMP 直播源接入 kode/messaging，
 * 业务层可以再分发到 WebSocket / SSE / UDP 等其他协议。
 *
> 不适用：作为 CDN 大规模 RTMP 分发（请用 nginx-rtmp / srs）。
 *
 * 运行：php examples/rtmp_server.php
 *
 * 本示例演示三层限流（基于 kode/limiting）：
 *  1. 连接级（按 IP 限并发连接数）
 *  2. 命令级（按 connection_id 限 AMF0 command 频率）
 *  3. 消息级（业务层通过中间件管道，对所有 message.received 限流）
 */

require __DIR__.'/../vendor/autoload.php';

use Kode\Messaging\Adapter\Rtmp\Server;
use Kode\Messaging\Messaging;
use Kode\Messaging\Middleware\RateLimit\RateLimitFactory;
use Kode\Messaging\Middleware\RateLimit\SlidingWindowMiddleware;
use Kode\Messaging\Middleware\RateLimit\TokenBucketMiddleware;

$config = require __DIR__.'/../config/messaging.php';

$builder = Messaging::server('rtmp://0.0.0.0:1935')
    ->on('connection.open', function ($conn): void {
        fwrite(STDOUT, "[rtmp] client connected: {$conn->remoteAddress()}\n");
    })
    ->on('message.received', function ($conn, $message): void {
        $event = $message->event();
        $topic = $message->topic();
        $ctx = $message->context();
        $rtmpType = $ctx['rtmp_type'] ?? null;

        if ($rtmpType === 0x08) {
            fwrite(STDOUT, "[rtmp] audio from {$conn->remoteAddress()} (ts={$ctx['timestamp']})\n");
        } elseif ($rtmpType === 0x09) {
            fwrite(STDOUT, "[rtmp] video from {$conn->remoteAddress()} (ts={$ctx['timestamp']})\n");
        } else {
            fwrite(STDOUT, "[rtmp] event={$event} topic={$topic} body={$message->payload()}\n");
        }
    })
    ->on('connection.close', function ($conn): void {
        fwrite(STDOUT, "[rtmp] client disconnected: {$conn->remoteAddress()}\n");
    })
    ->on('rate_limit.exceeded', function (array $payload): void {
        $type = $payload['type'];
        $peer = $payload['peer'];
        $info = $payload['info'];
        $wait = $info['wait_time'] ?? 0;
        fwrite(STDERR, "[rtmp] ⚠ rate limited: type={$type} peer={$peer} wait={$wait}s\n");
    });

// ============== 注入限流器到 RTMP 适配器 ==============
//
// 推荐方式：从 config/messaging.php 加载配置后用 RateLimitFactory 构造
//
$rlConfig = $config['rate_limit']['rtmp'] ?? null;
if ($rlConfig !== null) {
    /** @var Server $adapter */
    $adapter = $builder->adapter();

    $connLimiter = RateLimitFactory::create($rlConfig['connection']);
    $cmdLimiter = RateLimitFactory::create($rlConfig['command']);
    $adapter->setRateLimiters($connLimiter, $cmdLimiter);

    // 业务层消息级限流（中间件）
    //  - TokenBucketMiddleware：允许突发，适合"短时尖刺"流量整形
    //  - SlidingWindowMiddleware：精确控制瞬时流量
    $builder->middleware(
        TokenBucketMiddleware::memory(
            capacity: 1000,
            refillRate: 200.0,                 // 每秒 200 个 message
            keyPrefix: 'rtmp:msg:',
            keyField: 'connection_id',
        )
    );
    // 如需精确 QPS 控制，可叠加：
    // $builder->middleware(
    //     SlidingWindowMiddleware::memory(capacity: 500, windowSize: 1.0, keyPrefix: 'rtmp:msg:')
    // );
}

$builder->start();
