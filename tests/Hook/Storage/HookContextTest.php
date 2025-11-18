<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Hook\Storage\HookContext;

class HookContextTest extends TestCase
{
    public function testConstructorWithDefaultMaxDepth(): void
    {
        $context = new HookContext();

        self::assertSame(0, $context->getDepth());
        self::assertSame(3, $context->getMaxDepth());
        self::assertFalse($context->isDepthExceeded());
    }

    public function testConstructorWithCustomMaxDepth(): void
    {
        $context = new HookContext(5);

        self::assertSame(0, $context->getDepth());
        self::assertSame(5, $context->getMaxDepth());
        self::assertFalse($context->isDepthExceeded());
    }

    public function testConstructorThrowsExceptionWhenMaxDepthIsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max depth must be positive, got: 0');

        new HookContext(0);
    }

    public function testConstructorThrowsExceptionWhenMaxDepthIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max depth must be positive, got: -1');

        new HookContext(-1);
    }

    public function testIncrementDepth(): void
    {
        $context = new HookContext(3);

        $context->incrementDepth();
        self::assertSame(1, $context->getDepth());

        $context->incrementDepth();
        self::assertSame(2, $context->getDepth());

        $context->incrementDepth();
        self::assertSame(3, $context->getDepth());
    }

    public function testDecrementDepth(): void
    {
        $context = new HookContext(3);

        $context->incrementDepth();
        $context->incrementDepth();
        self::assertSame(2, $context->getDepth());

        $context->decrementDepth();
        self::assertSame(1, $context->getDepth());

        $context->decrementDepth();
        self::assertSame(0, $context->getDepth());
    }

    public function testDecrementDepthNeverGoesBelowZero(): void
    {
        $context = new HookContext(3);

        $context->decrementDepth();
        self::assertSame(0, $context->getDepth());

        $context->decrementDepth();
        self::assertSame(0, $context->getDepth());
    }

    public function testIsDepthExceededReturnsFalseWhenWithinLimit(): void
    {
        $context = new HookContext(3);

        self::assertFalse($context->isDepthExceeded());

        $context->incrementDepth();
        self::assertFalse($context->isDepthExceeded());

        $context->incrementDepth();
        self::assertFalse($context->isDepthExceeded());

        $context->incrementDepth();
        self::assertFalse($context->isDepthExceeded());
    }

    public function testIsDepthExceededReturnsTrueWhenLimitExceeded(): void
    {
        $context = new HookContext(3);

        $context->incrementDepth();
        $context->incrementDepth();
        $context->incrementDepth();
        $context->incrementDepth();

        self::assertTrue($context->isDepthExceeded());
    }

    public function testIsDepthExceededAtExactLimit(): void
    {
        $context = new HookContext(2);

        $context->incrementDepth();
        $context->incrementDepth();
        self::assertFalse($context->isDepthExceeded());

        $context->incrementDepth();
        self::assertTrue($context->isDepthExceeded());
    }

    public function testReset(): void
    {
        $context = new HookContext(3);

        $context->incrementDepth();
        $context->incrementDepth();
        $context->incrementDepth();
        self::assertSame(3, $context->getDepth());

        $context->reset();
        self::assertSame(0, $context->getDepth());
        self::assertFalse($context->isDepthExceeded());
    }

    public function testResetWithDepthExceeded(): void
    {
        $context = new HookContext(2);

        $context->incrementDepth();
        $context->incrementDepth();
        $context->incrementDepth();
        $context->incrementDepth();
        self::assertTrue($context->isDepthExceeded());

        $context->reset();
        self::assertFalse($context->isDepthExceeded());
        self::assertSame(0, $context->getDepth());
    }

    public function testMultipleIncrementAndDecrementCycles(): void
    {
        $context = new HookContext(3);

        // First cycle
        $context->incrementDepth();
        $context->incrementDepth();
        self::assertSame(2, $context->getDepth());
        $context->decrementDepth();
        $context->decrementDepth();
        self::assertSame(0, $context->getDepth());

        // Second cycle
        $context->incrementDepth();
        $context->incrementDepth();
        $context->incrementDepth();
        self::assertSame(3, $context->getDepth());
        $context->decrementDepth();
        $context->decrementDepth();
        $context->decrementDepth();
        self::assertSame(0, $context->getDepth());
    }
}
