<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

interface SessionIdGeneratorInterface
{
    public function generate(): string;
}
