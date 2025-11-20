<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Exception;

/**
 * セッション移行が失敗した場合にスローされる例外。
 */
class MigrationException extends RedisSessionException
{
}
