<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Exception;

use InvalidArgumentException;

/**
 * セッションIDが無効な形式の場合にスローされる例外。
 *
 * この例外は以下の場合にスローされます：
 * - セッションIDが空
 * - セッションIDに無効な文字が含まれる
 * - セッションIDが最大長を超える
 */
class InvalidSessionIdException extends InvalidArgumentException
{
}
