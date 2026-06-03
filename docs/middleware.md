# 中间件

中间件是 `kode/messaging` 的核心扩展点。所有消息在到达业务处理器前，会按顺序经过中间件管道。

## 1. 内置中间件

### 1.1 鉴权

```php
use Kode\Messaging\Middleware\Auth\{BearerAuthMiddleware, JwtAuthMiddleware, SignatureAuthMiddleware};

->middleware(new BearerAuthMiddleware($secret))
->middleware(new JwtAuthMiddleware($secretKey, 'HS256'))
->middleware(new SignatureAuthMiddleware($secret, 'X-Signature'))
```

### 1.2 限流

```php
use Kode\Messaging\Middleware\RateLimit\TokenBucketMiddleware;
use Kode\Messaging\Middleware\RateLimit\SlidingWindowMiddleware;

->middleware(new TokenBucketMiddleware(100, 60))    // 100 容量，60/s
->middleware(new SlidingWindowMiddleware(1000, 60)) // 60s 滑动窗口
```

### 1.3 编解码

```php
use Kode\Messaging\Middleware\Codec\{JsonCodec, MsgPackCodec, RawCodec};

->middleware(new JsonCodec())
->middleware(new MsgPackCodec())
->middleware(new RawCodec()) // 不处理，透传
```

### 1.4 校验

```php
use Kode\Messaging\Middleware\Validate\SchemaValidator;
use Kode\Messaging\Middleware\Validate\SizeValidator;

->middleware(new SizeValidator(maxBytes: 65536))
->middleware(new SchemaValidator([
    'type' => 'object',
    'properties' => [
        'event' => ['type' => 'string'],
        'data'  => ['type' => 'object'],
    ],
    'required' => ['event', 'data'],
]))
```

### 1.5 业务埋点

```php
use Kode\Messaging\Middleware\Trace\TraceMiddleware;

->middleware(new TraceMiddleware(driver: 'kode-context'))
```

为每条消息注入 trace id，跨调用链追踪。

## 2. 自定义中间件

```php
namespace App\Messaging\Middleware;

use Kode\Messaging\Contract\MessageInterface;
use Kode\Messaging\Middleware\MiddlewareInterface;

final class DeviceFilterMiddleware implements MiddlewareInterface
{
    public function __construct(private array $blockedDevices = []) {}

    public function process(MessageInterface $message, callable $next): MessageInterface
    {
        $deviceId = $message->headers()['x-device-id'] ?? null;
        if (in_array($deviceId, $this->blockedDevices, true)) {
            throw new \Kode\Messaging\Exception\MessagingException('设备已被封禁', 403);
        }
        return $next($message);
    }
}
```

注册：

```php
->middleware(new DeviceFilterMiddleware(['device-bad-001']))
```

## 3. 中间件顺序

注册顺序 = 执行顺序（洋葱圈）：

```php
->middleware(A)  // 1
->middleware(B)  // 2
->middleware(C)  // 3
->on('message.received', $handler)
```

执行流程：

```
message → A.before → B.before → C.before → handler → C.after → B.after → A.after → send
```

## 4. 全局中间件

```php
use Kode\Messaging\Messaging;

Messaging::globalMiddleware()
    ->push(new TraceMiddleware())
    ->push(new JsonCodec());
```

对所有 `server()` / `client()` 实例生效。

## 5. 异步中间件

```php
use Kode\Messaging\Middleware\Async\AsyncMiddleware;

->middleware(new AsyncMiddleware(driver: 'kode-fibers'))
```

把消息处理放到协程中，不阻塞接收循环。

## 6. 错误处理

中间件抛 `MessagingException` 时：

- 业务层 catch 后可返回错误消息
- 未 catch → 消息丢弃 + 派发 `error.middleware` 事件
- 不可恢复错误（如 OOM）→ 关闭连接

```php
->withExceptionHandler(function (ConnectionInterface $conn, \Throwable $e) {
    $conn->send(['event' => 'error', 'message' => $e->getMessage()]);
})
```

## 7. 调试

```php
->middleware(new DebugMiddleware(log: true))
```

记录每条消息经过的中间件与耗时。

## 8. 性能

中间件应保持轻量。耗时操作（DB、外部 HTTP）应：

- 推到协程：`AsyncMiddleware`
- 推到队列：`Kode\Queue\Queue::push()`
- 推到事件：`kode/event`

不要在中间件内同步阻塞。
