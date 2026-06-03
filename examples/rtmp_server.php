<?php

/**
 * RTMP 服务端示例
 *
 * 启动后，OBS / FMLE / ffmpeg 可推流到 rtmp://0.0.0.0:1935/live/<key>。
 *
 * 适用：把 RTMP 直播源接入 kode/messaging，
 * 业务层可以再分发到 WebSocket / SSE / UDP 等其他协议。
 *
> 不适用：作为 CDN 大规模 RTMP 分发（请用 nginx-rtmp / srs）。
 *
 * 运行：php examples/rtmp_server.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Messaging\Messaging;

Messaging::server('rtmp://0.0.0.0:1935')
    ->on('connection.open', function ($conn) {
        fwrite(STDOUT, "[rtmp] client connected: {$conn->remoteAddress()}\n");
    })
    ->on('message.received', function ($conn, $message) {
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
    ->on('connection.close', function ($conn) {
        fwrite(STDOUT, "[rtmp] client disconnected: {$conn->remoteAddress()}\n");
    })
    ->start();
