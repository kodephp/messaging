<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Support\PhpCompat;
use PHPUnit\Framework\TestCase;

final class PhpCompatTest extends TestCase
{
    public function testVersionIsAtLeast83(): void
    {
        $this->assertTrue(PhpCompat::isPhp83(), '需要 PHP 8.3 或更高');
        $this->assertGreaterThanOrEqual(80_300, PhpCompat::versionId());
    }

    public function testJsonValidateAlwaysAvailable(): void
    {
        // 8.3 基线：json_validate 始终可用
        $this->assertTrue(PhpCompat::jsonValidate('{"key":"value"}'));
        $this->assertFalse(PhpCompat::jsonValidate('{invalid}'));
    }

    public function testRandomBytes(): void
    {
        // 8.3 基线：Randomizer 始终可用
        $bytes = PhpCompat::randomBytes(16);
        $this->assertSame(16, strlen($bytes));
    }

    public function testPipeOperatorOnlyOn85(): void
    {
        $this->assertSame(PhpCompat::isPhp85(), PhpCompat::hasPipeOperator());
    }

    public function testPropertyHooksOnlyOn84(): void
    {
        $this->assertSame(PhpCompat::isPhp84(), PhpCompat::hasPropertyHooks());
    }

    public function testAsymmetricVisibilityOnlyOn84(): void
    {
        $this->assertSame(PhpCompat::isPhp84(), PhpCompat::hasAsymmetricVisibility());
    }
}
