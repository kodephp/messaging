<?php

declare(strict_types=1);

namespace Kode\Messaging\Tests\Unit;

use Kode\Messaging\Support\PhpCompat;
use PHPUnit\Framework\TestCase;

final class PhpCompatTest extends TestCase
{
    public function test_version_is_at_least83(): void
    {
        $this->assertTrue(PhpCompat::isPhp83(), '需要 PHP 8.3 或更高');
        $this->assertGreaterThanOrEqual(80_300, PhpCompat::versionId());
    }

    public function test_json_validate_always_available(): void
    {
        // 8.3 基线：json_validate 始终可用
        $this->assertTrue(PhpCompat::jsonValidate('{"key":"value"}'));
        $this->assertFalse(PhpCompat::jsonValidate('{invalid}'));
    }

    public function test_random_bytes(): void
    {
        // 8.3 基线：Randomizer 始终可用
        $bytes = PhpCompat::randomBytes(16);
        $this->assertSame(16, strlen($bytes));
    }

    public function test_pipe_operator_only_on85(): void
    {
        $this->assertSame(PhpCompat::isPhp85(), PhpCompat::hasPipeOperator());
    }

    public function test_property_hooks_only_on84(): void
    {
        $this->assertSame(PhpCompat::isPhp84(), PhpCompat::hasPropertyHooks());
    }

    public function test_asymmetric_visibility_only_on84(): void
    {
        $this->assertSame(PhpCompat::isPhp84(), PhpCompat::hasAsymmetricVisibility());
    }
}
