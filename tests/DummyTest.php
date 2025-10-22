<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Dummy;

class DummyTest extends TestCase
{
    public function testGetMessage(): void
    {
        $dummy = new Dummy();
        self::assertSame('Hello, World!', $dummy->getMessage());
    }

    public function testAdd(): void
    {
        $dummy = new Dummy();
        self::assertSame(5, $dummy->add(2, 3));
        self::assertSame(0, $dummy->add(-1, 1));
        self::assertSame(-5, $dummy->add(-2, -3));
    }
}
