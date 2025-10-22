<?php

namespace Uzulla\EnhancedRedisSessionHandler;

class Dummy
{
    public function getMessage(): string
    {
        return 'Hello, World!';
    }

    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
