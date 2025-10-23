<?php

namespace Uzulla\EnhancedRedisSessionHandler\SessionId;

interface SessionIdGeneratorInterface
{
    public function generate(): string;
}
