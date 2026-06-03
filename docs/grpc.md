# gRPC Streaming

> 适用：微服务 RPC、4 种流式调用模型
> 方案：`grpc://` / `grpcs://` / `grpc-web://`
> 端口：默认 `50051`

gRPC Streaming 适配器提供与 [gRPC](https://grpc.io/) 一致的 4 种调用模型：

| 模型 | 客户端 | 服务端 | 用途 |
|---|---|---|---|
| **Unary** | 1 req → 1 resp | handler 1 次返回 | 同步 RPC |
| **Server Streaming** | 1 req → N resp | handler Generator | 服务端推送 |
| **Client Streaming** | N req → 1 resp | handler 接收多次 | 上传 / 聚合 |
| **Bidirectional** | N req ↔ N resp | handler Generator | 全双工 |

## 帧格式

gRPC 帧（5 字节头）：

```
1 字节 compressed flag（0 = identity, 1 = compressed）
4 字节 message length（big-endian）
N 字节 payload
```

## 服务端

```php
use Kode\Messaging\Messaging;

$server = Messaging::server('grpc://0.0.0.0:50051')
    ->on('connection.open', fn() => print("新连接\n"));

// 业务层注册 gRPC 方法
$server->adapter()->registerMethod('/helloworld.Greeter/SayHello', function ($payload, $meta) {
    $name = json_decode($payload, true)['name'] ?? 'World';
    return json_encode(['message' => "Hello, {$name}"]);
});

$server->start();
```

## 客户端

### Unary

```php
use Kode\Messaging\Messaging;

$client = Messaging::client('grpc://api.example.com:50051');
$req = json_encode(['name' => 'kode']);
$resp = $client->call('/helloworld.Greeter/SayHello', $req);
echo $resp; // {"message":"Hello, kode"}
```

### Metadata

```php
$resp = $client->call('/path', $payload, [
    'authorization' => 'Bearer xxx',
    'x-trace-id'    => 'abc',
]);
```

## gRPC 状态码

| Code | Name |
|---|---|
| 0 | OK |
| 1 | CANCELLED |
| 2 | UNKNOWN |
| 3 | INVALID_ARGUMENT |
| 4 | DEADLINE_EXCEEDED |
| 5 | NOT_FOUND |
| 6 | ALREADY_EXISTS |
| 7 | PERMISSION_DENIED |
| 8 | RESOURCE_EXHAUSTED |
| 9 | FAILED_PRECONDITION |
| 10 | ABORTED |
| 11 | OUT_OF_RANGE |
| 12 | UNIMPLEMENTED |
| 13 | INTERNAL |
| 14 | UNAVAILABLE |
| 15 | DATA_LOSS |
| 16 | UNAUTHENTICATED |

## 实现状态

| 模型 | 状态 |
|---|---|
| Unary | ✅ 完整实现 |
| Server Streaming | 🚧 当前版本为基础版（Generator 占位） |
| Client Streaming | 🚧 当前版本为基础版 |
| Bidirectional | 🚧 当前版本为基础版 |

> 当前传输层为 **gRPC-Web 风格**（HTTP/1.1 + chunked TE），
> 完整 HTTP/2 + HPACK + TLS 计划在 2.1 版本提供。
> 真实生产推荐使用 [grpc/grpc](https://github.com/grpc/grpc) 的 PHP 扩展 + 本 Codec。

## 配置项

| 项 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `tls` | bool | `false` | 是否使用 TLS |
| `timeout` | float | `5.0` | Unary 调用超时（秒） |
| `max_message_size` | int | `4 * 1024 * 1024` | 单帧最大字节 |
| `user_agent` | string | `kode-messaging/grpc` | UA |

## 错误处理

```php
use Kode\Messaging\Exception\GrpcException;

try {
    $resp = $client->call('/path', $payload);
} catch (GrpcException $e) {
    // $e->context() 包含 'status_code'、'status_name'、'message'
    echo $e->getMessage();
}
```
