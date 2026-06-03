<?php

declare(strict_types=1);

namespace Kode\Messaging\Adapter;

use Kode\Messaging\Contract\AdapterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 适配器基类
 *
 * 提供：
 *  - 配置管理
 *  - Logger 注入
 *  - 运行状态（idle / running / shutting-down）
 */
abstract class AbstractAdapter implements AdapterInterface
{
    protected array $config = [];
    protected LoggerInterface $logger;
    protected bool $running = false;
    protected string $host = '0.0.0.0';
    protected int $port = 0;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function version(): string
    {
        return '1.0';
    }

    public function boot(array $config): void
    {
        $this->config = array_replace_recursive($this->defaultConfig(), $config);
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
