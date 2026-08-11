<?php

declare(strict_types=1);

namespace Kode\Messaging\Connection;

use Kode\Messaging\Contract\AuthContext;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Support\IdGenerator;
use RuntimeException;
use Throwable;

/**
 * 协议无关连接（默认实现）
 *
 * 具体协议（WebSocket/SSE/MQTT/UDP）的 Connection 类可继承此类，复用通用逻辑。
 */
class Connection implements ConnectionInterface
{
    /** @var array<string, mixed> */
    protected array $attrs = [];

    protected bool $open = true;

    /** @var list<callable(null|Throwable):void> */
    protected array $closeCallbacks = [];

    protected ?AuthContext $authContext = null;

    public function __construct(
        protected string $connId,
        protected string $protocol,
        protected string $remoteAddress,
    ) {}

    public function id(): string
    {
        return $this->connId;
    }

    public function protocol(): string
    {
        return $this->protocol;
    }

    public function remoteAddress(): string
    {
        return $this->remoteAddress;
    }

    public function send(mixed $payload, array $options = []): bool
    {
        // 由具体协议适配器覆盖
        return false;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if (! $this->open) {
            return;
        }
        $this->open = false;
        $err = null;
        if ($code !== 1000 && $code !== 0) {
            $err = new RuntimeException("Connection closed: code={$code} reason={$reason}", $code);
        }
        foreach ($this->closeCallbacks as $cb) {
            try {
                $cb($err);
            } catch (Throwable) {
                // 静默吞掉，避免影响其它回调
            }
        }
        $this->closeCallbacks = [];
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function attributes(): array
    {
        return $this->attrs;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attrs[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attrs[$key] ?? $default;
    }

    public function onClose(callable $callback): void
    {
        $this->closeCallbacks[] = $callback;
        if (! $this->open) {
            // 已关闭，立即回调一次
            try {
                $callback(null);
            } catch (Throwable) {
            }
        }
    }

    public function authContext(): ?AuthContext
    {
        return $this->authContext;
    }

    public function setAuthContext(AuthContext $ctx): void
    {
        $this->authContext = $ctx;
        $this->attrs['auth.user_id'] = $ctx->userId;
        $this->attrs['auth.scopes'] = $ctx->scopes;
    }

    /**
     * 工厂：生成新连接 ID。
     */
    public static function generateId(string $protocol): string
    {
        return $protocol.'-'.IdGenerator::random();
    }
}
