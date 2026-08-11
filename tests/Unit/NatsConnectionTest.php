<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Adapter\Nats\NatsConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class NatsConnectionTest extends TestCase
{
    public function test_subject_matching(): void
    {
        $conn = new NatsConnection('test-1', 'nats', '127.0.0.1:4222', fopen('php://memory', 'r+'));

        $ref = new ReflectionClass($conn);
        $method = $ref->getMethod('matchSubject');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($conn, 'orders.*', 'orders.created'));
        $this->assertTrue($method->invoke($conn, 'orders.*', 'orders.deleted'));
        $this->assertFalse($method->invoke($conn, 'orders.*', 'payments.created'));
        $this->assertTrue($method->invoke($conn, 'orders.>', 'orders.created'));
        $this->assertTrue($method->invoke($conn, 'orders.>', 'orders.eu.fr.created'));
        $this->assertFalse($method->invoke($conn, 'orders.>', 'payments.created'));
        $this->assertTrue($method->invoke($conn, 'foo.bar', 'foo.bar'));
        $this->assertFalse($method->invoke($conn, 'foo.bar', 'foo.bar.baz'));
        $this->assertTrue($method->invoke($conn, '>', 'anything.at.all'));

        $conn->close();
    }
}
