<?php

/**
 * gRPC Streaming 服务端示例
 *
 * 启动后，业务可使用 grpc://0.0.0.0:50051 调用 /helloworld.Greeter/SayHello。
 *
 * 运行：php examples/grpc_server.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Messaging\Adapter\Grpc\Server as GrpcServer;
use Kode\Messaging\Messaging;

$server = Messaging::server('grpc://0.0.0.0:50051');

/** @var GrpcServer $adapter */
$adapter = $server->adapter();
$adapter->registerMethod('/helloworld.Greeter/SayHello', function (string $payload, array $meta): string {
    $req = json_decode($payload, true);
    $name = $req['name'] ?? 'World';
    return json_encode(['message' => "Hello, {$name}"]);
});

fwrite(STDOUT, "[gRPC] listening on 0.0.0.0:50051\n");
$server->start();
