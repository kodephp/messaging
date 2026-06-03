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
     * 建立连接（返回 Connection）。
     */
    public function connect(): ConnectionInterface
    {
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
        $this->emit('open', ['connection' => $this->connection]);
        return $this->connection;
    }

    /**
     * 发送消息。
     */
    public function send(mixed $payload, array $options = []): bool
    {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection?->send($payload, $options) ?? false;
    }

    /**
     * 关闭连接。
     */
    public function disconnect(int $code = 1000, string $reason = ''): void
    {
        $this->connection?->close($code, $reason);
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
        $class = Registry::find($this->scheme);
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
