<?php

/**
 * WebTransport 占位服务示例
 *
 * 浏览器原生 WebTransport 走 HTTP/3；
 * 本示例展示业务层如何挂接：
 *  1. 启动本服务（HTTP/2-fallback 占位）
 *  2. 由外部 HTTP/3 后端（aioquic / msquic）调用 dispatchBidirectional() 等方法
 *  3. 业务回调通过 onBidirectional() 等注册
 *
 * 运行：php examples/webtransport_server.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Messaging\Adapter\WebTransport\Server as WtServer;
use Kode\Messaging\Messaging;

$server = Messaging::server('wt://0.0.0.0:4433');

/** @var WtServer $adapter */
$adapter = $server->adapter();

$adapter->onBidirectional('session-1', function (string $payload, array $meta) {
    fwrite(STDOUT, "[wt-bidi] {$payload}\n");
});
$adapter->onUnidirectional('session-1', function (string $payload, array $meta) {
    fwrite(STDOUT, "[wt-unidi] {$payload}\n");
});
$adapter->onDatagram('session-1', function (string $payload, array $meta) {
    fwrite(STDOUT, "[wt-dgram] {$payload}\n");
});

fwrite(STDOUT, "[wt] listening on 0.0.0.0:4433 (HTTP/2-fallback, see docs/webtransport.md)\n");
$server->start();
