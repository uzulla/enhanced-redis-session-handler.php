<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Serializer;

use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;

/**
 * PHP session serializer for 'php_serialize' format.
 *
 * The 'php_serialize' format uses standard PHP serialize() format.
 * This is the default format in PHP 7.0+.
 */
class PhpSerializeSerializer implements SessionSerializerInterface
{
    /**
     * Deserialize session data using standard PHP unserialize().
     *
     * @param string $data Raw session data
     * @return array<string, mixed> Associative array of session variables
     * @throws SessionDataException on malformed input
     */
    public function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        set_error_handler(static function (): bool {
            return true; // Suppress the error
        });
        try {
            $unserialized = unserialize($data, ['allowed_classes' => true]);
        } finally {
            restore_error_handler();
        }

        if ($unserialized === false && $data !== 'b:0;') {
            throw new SessionDataException('Failed to unserialize session data');
        }

        if (!is_array($unserialized)) {
            throw new SessionDataException('Session data is not an array, got: ' . gettype($unserialized));
        }

        /** @var array<string, mixed> $unserialized */
        return $unserialized;
    }

    /**
     * Serialize an associative array using standard PHP serialize().
     *
     * @param array<string, mixed> $data
     * @return string
     * @throws SessionDataException on serialization failure
     */
    public function encode(array $data): string
    {
        set_error_handler(static function (): bool {
            return true; // Suppress the error
        });
        try {
            $serialized = serialize($data);
        } finally {
            restore_error_handler();
        }

        return $serialized;
    }

    /**
     * Get the name of the serializer.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'php_serialize';
    }
}
