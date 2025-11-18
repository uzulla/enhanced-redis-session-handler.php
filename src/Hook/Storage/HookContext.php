<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook\Storage;

use InvalidArgumentException;

/**
 * Manages execution depth tracking for hook storage operations.
 *
 * This class prevents infinite recursion by tracking how deeply nested
 * hook operations are. When hooks perform Redis operations (which themselves
 * may trigger hooks), we need to track this depth and prevent runaway recursion.
 *
 * Design decisions:
 * - Default maximum depth of 3 levels is sufficient for typical use cases
 * - Thread-safe for single-threaded PHP environments (uses instance state)
 * - Minimal performance overhead from simple integer increment/decrement
 *
 * Example usage:
 * ```php
 * $context = new HookContext(3);
 * $context->incrementDepth();
 * try {
 *     // Perform operation
 *     if ($context->isDepthExceeded()) {
 *         // Handle depth limit exceeded
 *     }
 * } finally {
 *     $context->decrementDepth();
 * }
 * ```
 */
class HookContext
{
    /**
     * Default maximum depth for hook execution chain.
     */
    private const DEFAULT_MAX_DEPTH = 3;

    /**
     * Current execution depth counter.
     *
     * @var int
     */
    private int $depth = 0;

    /**
     * Maximum allowed execution depth.
     *
     * @var int
     */
    private int $maxDepth;

    /**
     * Create a new hook context.
     *
     * @param int $maxDepth Maximum allowed execution depth (must be positive)
     * @throws InvalidArgumentException If maxDepth is not positive
     */
    public function __construct(int $maxDepth = self::DEFAULT_MAX_DEPTH)
    {
        if ($maxDepth <= 0) {
            throw new InvalidArgumentException('Max depth must be positive, got: ' . $maxDepth);
        }

        $this->maxDepth = $maxDepth;
    }

    /**
     * Increment the execution depth counter.
     *
     * Call this method when entering a hook storage operation.
     *
     * @return void
     */
    public function incrementDepth(): void
    {
        $this->depth++;
    }

    /**
     * Decrement the execution depth counter.
     *
     * Call this method when exiting a hook storage operation.
     * The depth will never go below 0.
     *
     * @return void
     */
    public function decrementDepth(): void
    {
        if ($this->depth > 0) {
            $this->depth--;
        }
    }

    /**
     * Get the current execution depth.
     *
     * @return int Current depth (0 or greater)
     */
    public function getDepth(): int
    {
        return $this->depth;
    }

    /**
     * Get the maximum allowed execution depth.
     *
     * @return int Maximum depth
     */
    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * Check if the current depth exceeds the maximum allowed depth.
     *
     * @return bool True if depth limit is exceeded, false otherwise
     */
    public function isDepthExceeded(): bool
    {
        return $this->depth > $this->maxDepth;
    }

    /**
     * Reset the execution depth counter to zero.
     *
     * Use this method with caution. It's primarily intended for testing
     * or specific edge cases where you need to reset the context state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->depth = 0;
    }
}
