<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter;

use Kode\Messaging\Contract\AdapterInterface;
use Kode\Messaging\Contract\BusInterface;
use Kode\Messaging\Support\RuntimeDetector;
use Kode\Messaging\Transport\TransportFactory;
use Kode\Messaging\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 适配器基类
 *
 * 提供：
 *  - 配置管理
 *  - Logger 注入
 *  - 运行状态（idle / running / shutting-down）
 *  - 传输层自动检测（stream / swoole / swow / workerman）
 */
abstract class AbstractAdapter implements AdapterInterface
{
    protected array $config = [];

    protected LoggerInterface $logger;

    protected bool $running = false;

    protected string $host = '0.0.0.0';

    protected int $port = 0;

    protected ?TransportInterface $transport = null;

    protected ?BusInterface $bus = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 注入跨节点消息总线（集群模式）。
     */
    public function setBus(BusInterface $bus): void
    {
        $this->bus = $bus;
    }

    public function getBus(): ?BusInterface
    {
        return $this->bus;
    }

    public function version(): string
    {
        return '1.0';
    }

    public function boot(array $config): void
    {
        $this->config = array_replace_recursive($this->defaultConfig(), $config);
        $this->initTransport();
    }

    /**
     * 初始化传输层 —— 根据配置或自动检测选择最佳传输驱动。
     */
    protected function initTransport(): void
    {
        $driver = $this->config['transport'] ?? 'auto';
        $this->transport = TransportFactory::create($driver === 'auto' ? null : $driver);
        $this->logger->debug('传输层已初始化', [
            'driver' => $this->transport->driver(),
            'runtime' => RuntimeDetector::runtime(),
        ]);
    }

    /**
     * 获取当前传输层实例。
     */
    protected function transport(): TransportInterface
    {
        return $this->transport ??= TransportFactory::create();
    }

    /**
     * 子类覆盖以提供默认配置。
     *
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [];
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * 子类实现：实际运行循环。
     */
    abstract public function run(): void;

    /**
     * 默认 shutdown：标记状态，由子类具体执行。
     */
    public function shutdown(): void
    {
        $this->running = false;
    }
}
