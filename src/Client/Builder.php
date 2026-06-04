<?php

declare(strict_types=1);

namespace Kode\Messaging\Client;

use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\AdapterInterface;
use Kode\Messaging\Contract\ConnectionInterface;
use Kode\Messaging\Contract\MiddlewareInterface;
use Kode\Messaging\Event\Dispatcher;
use Kode\Messaging\Exception\AdapterNotFoundException;
use Kode\Messaging\Middleware\Pipeline;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 客户端构建器
 */
final class Builder
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    private Pipeline $pipeline;

    private ?Dispatcher $dispatcher = null;

    private ?LoggerInterface $logger = null;

    private ?ConnectionInterface $connection = null;

    private int $reconnectMax = 0;
    private int $reconnectDelayMs = 1000;
    private bool $reconnect = false;

    private int $heartbeatInterval = 0;

    private ?string $clientId = null;
    private ?string $username = null;
    private ?string $password = null;

    public function __construct(
        private readonly string $scheme,
        private readonly array $userConfig = [],
        private readonly array $globalConfig = [],
    ) {
        $this->pipeline = new Pipeline();
    }

    public function on(string $event, callable $handler): self
    {
        $this->listeners[$event][] = $handler;
        return $this;
    }

    public function middleware(MiddlewareInterface|callable $mw): self
    {
        $this->pipeline->push($mw);
        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger ??= new NullLogger();
    }

    public function withReconnect(int $maxAttempts = 5, int $delayMs = 1000): self
    {
        $this->reconnect = true;
        $this->reconnectMax = $maxAttempts;
        $this->reconnectDelayMs = $delayMs;
        return $this;
    }

    public function withHeartbeat(int $intervalSeconds): self
    {
        $this->heartbeatInterval = $intervalSeconds;
        return $this;
    }

    public function withClientId(string $id): self
    {
        $this->clientId = $id;
        return $this;
    }

    public function withCredentials(?string $username = null, ?string $password = null): self
    {
        $this->username = $username;
        $this->password = $password;
        return $this;
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    /**
     * 归一化后的协议名（用于 Registry 查找）。
     *
     * 用户传入 `mqtt://broker:1883` 也能映射到注册表里的 `mqtt`。
     */
    private function normalizedScheme(): string
    {
        $url = \Kode\Messaging\Messaging::parseUrl($this->scheme);
        return $url['scheme'];
    }

    /**
     * 建立连接（返回 Connection）。
     *
     * 该方法是幂等的：第一次调用真实建连，后续调用直接返回已存在的连接。
     * 若连接失败抛出 {@see AdapterNotFoundException} 或底层连接异常。
     */
    public function connect(): ConnectionInterface
    {
        if ($this->connection !== null && $this->connection->isOpen()) {
            return $this->connection;
        }
        $adapter = $this->makeAdapter();
        $url = \Kode\Messaging\Messaging::parseUrl($this->scheme);
        $merged = array_replace_recursive(
            $this->globalConfig[$url['scheme']] ?? [],
            $this->userConfig,
            [
                'client_id'  => $this->clientId,
                'username'   => $this->username,
                'password'   => $this->password,
                'heartbeat'  => $this->heartbeatInterval,
                'reconnect'  => [
                    'enabled' => $this->reconnect,
                    'max'     => $this->reconnectMax,
                    'delay'   => $this->reconnectDelayMs,
                ],
                'url'        => $this->scheme,
                'tls'        => $url['tls'],
                'host'       => $url['host'],
                'port'       => $url['port'],
                'path'       => $url['path'],
                'query'      => $url['query'],
            ],
        );
        $adapter->boot($merged);
        $this->connection = $adapter->connect($merged);
        if ($this->connection === null) {
            throw new \RuntimeException(
                "kode/messaging: 适配器 {$this->scheme}::connect() 返回 null，连接未建立"
            );
        }
        $this->emit('open', ['connection' => $this->connection]);
        return $this->connection;
    }

    /**
     * 确保已建立连接，返回非空的连接对象。
     *
     * @throws \RuntimeException 当连接未建立或已关闭
     */
    private function ensureConnected(): ConnectionInterface
    {
        if ($this->connection === null) {
            $this->connect();
        }
        if ($this->connection === null || !$this->connection->isOpen()) {
            throw new \RuntimeException(
                "kode/messaging: 协议 {$this->scheme} 连接尚未建立或已关闭，请先调用 connect()"
            );
        }
        return $this->connection;
    }

    /**
     * 发送消息。
     */
    public function send(mixed $payload, array $options = []): bool
    {
        $conn = $this->ensureConnected();
        return $conn->send($payload, $options);
    }

    /**
     * 关闭连接。
     */
    public function disconnect(int $code = 1000, string $reason = ''): void
    {
        $this->connection?->close($code, $reason);
        $this->connection = null;
    }

    /**
     * 协议级订阅（topic 形式）——委托给适配器的 subscribe() 方法。
     *
     * 适用：MQTT / NATS / STOMP / CoAP。
     * 业务也可改用 on('message.received', ...) 处理收到的消息。
     *
     * @return mixed 适配器返回的订阅句柄（int sid / string sub-id / null）
     *
     * @throws \LogicException   适配器不支持 subscribe()
     * @throws \RuntimeException 连接未建立 / 已关闭
     */
    public function subscribe(string $topic, callable $handler, mixed $extra = null): mixed
    {
        $adapter = $this->makeAdapter();
        if (!method_exists($adapter, 'subscribe')) {
            throw new \LogicException("适配器 {$this->scheme} 不支持 subscribe()");
        }
        $this->ensureConnected();
        return $adapter->subscribe($topic, $handler, $extra);
    }

    /**
     * 协议级发布。
     *
     * @throws \LogicException   适配器不支持 publish()
     * @throws \RuntimeException 连接未建立 / 已关闭
     */
    public function publish(string $topic, string $payload, mixed $extra = null): void
    {
        $adapter = $this->makeAdapter();
        if (!method_exists($adapter, 'publish')) {
            throw new \LogicException("适配器 {$this->scheme} 不支持 publish()");
        }
        $this->ensureConnected();
        $adapter->publish($topic, $payload, $extra);
    }

    /**
     * 进入客户端事件循环。
     */
    public function loop(): void
    {
        $adapter = $this->makeAdapter();
        $adapter->run();
    }

    public function makeAdapter(): AdapterInterface
    {
        $class = Registry::find($this->normalizedScheme());
        if ($class === null) {
            throw AdapterNotFoundException::forScheme($this->scheme, Registry::schemes());
        }
        return new $class($this->logger);
    }

    private function emit(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener(new \Kode\Messaging\Event\Event($event, $payload));
            } catch (\Throwable $e) {
                $this->logger()->error('client listener error', ['event' => $event, 'error' => $e->getMessage()]);
            }
        }
    }

    public function connection(): ?ConnectionInterface
    {
        return $this->connection;
    }
}
