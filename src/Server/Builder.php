<?php

declare(strict_types=1);

namespace Kode\Messaging\Server;

use Kode\Messaging\Adapter\Registry;
use Kode\Messaging\Contract\AdapterInterface;
use Kode\Messaging\Contract\AuthenticatorInterface;
use Kode\Messaging\Contract\MiddlewareInterface;
use Kode\Messaging\Event\Dispatcher;
use Kode\Messaging\Exception\AdapterNotFoundException;
use Kode\Messaging\Middleware\Pipeline;
use Kode\Messaging\Router\Router;
use Kode\Messaging\Support\IdGenerator;
use Psr\Log\LoggerInterface;

/**
 * 服务端构建器
 *
 * 用例：
 *   Messaging::server('ws://0.0.0.0:8080')
 *     ->on('connection.open', $h)
 *     ->on('message.received', $h)
 *     ->middleware(new MyAuth())
 *     ->withRouter($router)
 *     ->start();
 */
final class Builder
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    private Pipeline $pipeline;

    private ?Router $router = null;

    private ?AuthenticatorInterface $authenticator = null;

    private ?Dispatcher $dispatcher = null;

    private ?LoggerInterface $logger = null;

    /** @var array<string, callable> 定时器（毫秒 => callback） */
    private array $intervals = [];

    private bool $withCluster = false;
    private ?string $nodeId = null;

    public function __construct(
        private readonly string $scheme,
        private readonly array $userConfig = [],
        private readonly array $globalConfig = [],
    ) {
        $this->pipeline = new Pipeline();
    }

    /**
     * 监听事件。
     *
     * 支持的事件名：
     *  - server.start / server.stop
     *  - connection.open / connection.close
     *  - message.received / message.sent
     *  - interval             (定时器，需配合 interval(int $ms))
     *  - error.protocol / error.codec / error.transport
     */
    public function on(string $event, callable $handler): self
    {
        $this->listeners[$event][] = $handler;
        return $this;
    }

    /**
     * 触发事件（业务层也可调用）。
     */
    public function emit(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener(new \Kode\Messaging\Event\Event($event, $payload));
            } catch (\Throwable $e) {
                if ($this->logger !== null) {
                    $this->logger->error('listener error', ['event' => $event, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    public function middleware(MiddlewareInterface|callable $mw): self
    {
        $this->pipeline->push($mw);
        return $this;
    }

    public function withPipeline(Pipeline $pipeline): self
    {
        $this->pipeline = $pipeline;
        return $this;
    }

    public function pipeline(): Pipeline
    {
        return $this->pipeline;
    }

    public function withRouter(?Router $router): self
    {
        $this->router = $router;
        return $this;
    }

    public function router(): ?Router
    {
        return $this->router;
    }

    public function withAuthenticator(AuthenticatorInterface $auth): self
    {
        $this->authenticator = $auth;
        return $this;
    }

    public function authenticator(): ?AuthenticatorInterface
    {
        return $this->authenticator;
    }

    public function withEventDispatcher(Dispatcher $dispatcher): self
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    public function eventDispatcher(): ?Dispatcher
    {
        return $this->dispatcher;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * 注册定时器（毫秒）。
     */
    public function interval(int $ms, ?callable $handler = null): self
    {
        if ($handler !== null) {
            $this->intervals[(string)$ms] = $handler;
        }
        return $this;
    }

    /**
     * @return array<string, callable>
     */
    public function intervals(): array
    {
        return $this->intervals;
    }

    public function withCluster(bool $enable = true, ?string $nodeId = null): self
    {
        $this->withCluster = $enable;
        $this->nodeId = $nodeId ?? IdGenerator::random();
        return $this;
    }

    public function clusterEnabled(): bool
    {
        return $this->withCluster;
    }

    public function nodeId(): ?string
    {
        return $this->nodeId;
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    /**
     * @return array<string, mixed>
     */
    public function userConfig(): array
    {
        return $this->userConfig;
    }

    /**
     * @return array<string, mixed>
     */
    public function globalConfig(): array
    {
        return $this->globalConfig;
    }

    /**
     * 启动服务（阻塞主循环）。
     */
    public function start(): void
    {
        $adapter = $this->makeAdapter();
        $url = \Kode\Messaging\Messaging::parseUrl($this->scheme);
        $merged = array_replace_recursive(
            $this->globalConfig[$url['scheme']] ?? [],
            $this->userConfig,
        );
        $adapter->boot($merged);

        // 集群模式：创建跨节点消息总线
        if ($this->withCluster) {
            $this->initClusterBus($adapter);
        }

        $adapter->listen($url['host'], $url['port']);
        $this->emit('server.start', ['scheme' => $this->scheme, 'host' => $url['host'], 'port' => $url['port']]);
        try {
            $adapter->run();
        } finally {
            $this->emit('server.stop', []);
        }
    }

    /**
     * 初始化集群消息总线。
     *
     * 根据配置选择 RedisBus（跨节点）或 ChannelBus（单节点多 Worker）。
     * 任一驱动初始化失败都降级为单节点模式，不影响服务启动。
     */
    private function initClusterBus(AdapterInterface $adapter): void
    {
        $clusterConfig = $this->globalConfig['cluster'] ?? [];
        $driver = $clusterConfig['driver'] ?? 'redis';
        $logger = $this->logger ?? \Kode\Messaging\Messaging::logger();

        try {
            if ($driver === 'channel') {
                $bus = new \Kode\Messaging\PubSub\ChannelBus(
                    $clusterConfig['channel'] ?? [],
                    $logger,
                );
                $target = 'channel';
            } else {
                $redisConfig = $this->globalConfig['redis'] ?? [];
                $host = $redisConfig['host'] ?? '127.0.0.1';
                $port = $redisConfig['port'] ?? 6379;
                $bus = new \Kode\Messaging\PubSub\RedisBus([
                    'host'   => $host,
                    'port'   => $port,
                    'prefix' => $redisConfig['prefix'] ?? 'kode:messaging:',
                ], $logger);
                $target = "{$host}:{$port}";
            }

            if ($adapter instanceof \Kode\Messaging\Adapter\AbstractAdapter) {
                $adapter->setBus($bus);
            }
            $logger->info('集群总线已启用', [
                'driver'  => $driver,
                'node_id' => $this->nodeId,
                'target'  => $target,
            ]);
        } catch (\Throwable $e) {
            // 原实现在未注入 logger 时会因 null->warning() 直接致命错误
            $logger->warning('集群总线初始化失败，降级为单节点模式', [
                'driver' => $driver,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    public function makeAdapter(): AdapterInterface
    {
        $class = Registry::find($this->scheme);
        if ($class === null) {
            throw AdapterNotFoundException::forScheme($this->scheme, Registry::schemes());
        }
        return new $class($this->logger);
    }

    /**
     * 创建并返回适配器实例（业务层可用于挂接协议级回调）。
     */
    public function adapter(): AdapterInterface
    {
        return $this->makeAdapter();
    }
}
