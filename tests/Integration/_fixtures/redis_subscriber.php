<?php

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use Kode\Messaging\PubSub\RedisBus;

$prefix = $argv[1] ?? '';
$ready = $argv[2] ?? '';
$out = $argv[3] ?? '';

$bus = new RedisBus([
    'host' => getenv('KODE_TEST_REDIS_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('KODE_TEST_REDIS_PORT') ?: 6379),
    'password' => getenv('KODE_TEST_REDIS_PASSWORD') ?: null,
    'prefix' => $prefix,
]);

foreach (['one', 'two', 'three'] as $name) {
    $bus->subscribe('t/'.$name, static function (array $p) use ($out, $name): void {
        file_put_contents($out, $name.':'.$p['id']."\n", FILE_APPEND);
    });
}

file_put_contents($ready, 'ready');

// 阻塞收流；父进程收尾时 terminate
$bus->loop(60);
