<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Serializer;

/**
 * Interface for session data serialization/deserialization.
 *
 * This interface allows supporting different PHP session.serialize_handler formats
 * such as 'php', 'php_serialize', and 'php_binary'.
 */
interface SessionSerializerInterface
{
    /**
     * Deserialize session data string to an associative array.
     *
     * @param string $data Raw session data
     * @return array<string, mixed> Associative array of session variables
     * @throws \Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException on malformed input
     */
    public function decode(string $data): array;

    /**
     * Serialize an associative array into session data string.
     *
     * @param array<string, mixed> $data Associative array of session variables
     * @return string Serialized session data
     * @throws \Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException on serialization failure
     */
    public function encode(array $data): string;

    /**
     * Get the name of the serializer (e.g., 'php', 'php_serialize').
     *
     * @return string
     */
    public function getName(): string;
}
