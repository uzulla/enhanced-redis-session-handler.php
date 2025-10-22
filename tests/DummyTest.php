<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Dummy;

class DummyTest extends TestCase
{
    public function testGetMessage(): void
    {
        $dummy = new Dummy();
        $this->assertSame('Hello, World!', $dummy->getMessage());
    }

    public function testAdd(): void
    {
        $dummy = new Dummy();
        $this->assertSame(5, $dummy->add(2, 3));
        $this->assertSame(0, $dummy->add(-1, 1));
        $this->assertSame(-5, $dummy->add(-2, -3));
    }
}
