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

    public function testHasStandaloneTypes(): void
    {
        $this->assertTrue(PhpCompat::hasStandaloneTypes());
    }

    public function testJsonValidateOn83(): void
    {
        $has = PhpCompat::hasJsonValidate();
        $this->assertSame(PhpCompat::isPhp83(), $has);
    }

    public function testPipeOperatorOnlyOn85(): void
    {
        $this->assertSame(PhpCompat::isPhp85(), PhpCompat::hasPipeOperator());
    }

    public function testRandomizerOn83(): void
    {
        $this->assertSame(PhpCompat::isPhp83(), PhpCompat::hasRandomizer());
    }
}
