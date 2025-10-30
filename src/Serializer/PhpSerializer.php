<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Serializer;

use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;

/**
 * PHP session serializer for 'php' format.
 *
 * The 'php' format uses a custom serialization format where each variable is stored as:
 * key|serialized_value
 *
 * For example: foo|s:3:"bar";test|i:123;
 *
 * This is different from 'php_serialize' which uses standard PHP serialize() format.
 */
class PhpSerializer implements SessionSerializerInterface
{
    /**
     * Deserialize a session-serialized string (php handler) to an associative array.
     *
     * @param string $data Raw session data (e.g. "key|serialized_value key2|...")
     * @return array<string, mixed> Associative array of session variables
     * @throws SessionDataException on malformed input
     */
    public function decode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        $result = [];
        $offset = 0;
        $len = strlen($data);

        while ($offset < $len) {
            $pos = strpos($data, '|', $offset);
            if ($pos === false) {
                $rest = substr($data, $offset);
                if ($rest === '' || trim($rest) === '') {
                    break;
                }
                throw new SessionDataException('Malformed session data: missing "|" after key at offset ' . $offset);
            }

            $key = substr($data, $offset, $pos - $offset);
            $offset = $pos + 1;

            $parsed = $this->consumeSerializedValue($data, $offset);
            if ($parsed === null) {
                throw new SessionDataException('Malformed serialized value for key "' . $key . '" at offset ' . $offset);
            }

            [$serializedString, $nextOffset] = $parsed;

            set_error_handler(static function (): bool {
                return true; // Suppress the error
            });
            try {
                $value = unserialize($serializedString, ['allowed_classes' => true]);
            } finally {
                restore_error_handler();
            }

            if ($value === false && $serializedString !== 'b:0;') {
                throw new SessionDataException('unserialize failed for key "' . $key . '" with data: ' . $serializedString);
            }

            $result[$key] = $value;
            $offset = $nextOffset;
        }

        return $result;
    }

    /**
     * Serialize an associative array into php session format.
     *
     * @param array<int|string, mixed> $data
     * @return string
     * @throws SessionDataException on serialization failure
     */
    public function encode(array $data): string
    {
        $out = '';
        foreach ($data as $key => $value) {
            if (is_int($key)) {
                $keyString = (string)$key;
            } else {
                $keyString = $key;
            }

            set_error_handler(static function (): bool {
                return true; // Suppress the error
            });
            try {
                $serialized = serialize($value);
            } finally {
                restore_error_handler();
            }

            $out .= $keyString . '|' . $serialized;
        }
        return $out;
    }

    /**
     * Get the name of the serializer.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'php';
    }

    /**
     * Consume a serialized value starting at $start offset in $data.
     * Returns [serializedString, nextOffset] or null on failure.
     *
     * This is a deterministic parser for PHP serialize format tokens:
     *  - N; (NULL)
     *  - b:0; or b:1;
     *  - i:<number>;
     *  - d:<float>;
     *  - s:<length>:"...";
     *  - a:<length>:{...}
     *  - O:<strlen>:"ClassName":<props>:{...}
     *  - C:<strlen>:"ClassName":<len>:{...}  (custom serialized object)
     *
     * Note: For objects (O, C) this function will parse boundaries but does not interpret internals.
     *
     * @param string $data
     * @param int $start
     * @return array{0: string, 1: int}|null [string $serialized, int $nextOffset] or null
     */
    private function consumeSerializedValue(string $data, int $start): ?array
    {
        $len = strlen($data);
        if ($start >= $len) {
            return null;
        }

        $type = $data[$start];

        switch ($type) {
            case 'N': // N;
                if (substr($data, $start, 2) === 'N;') {
                    return ['N;', $start + 2];
                }
                return null;

            case 'b': // b:0; or b:1;
            case 'i': // i:123;
            case 'd': // d:0.12;
                $semicolonPos = strpos($data, ';', $start);
                if ($semicolonPos === false) {
                    return null;
                }
                $serialized = substr($data, $start, $semicolonPos - $start + 1);
                return [$serialized, $semicolonPos + 1];

            case 's': // s:len:"...";
                $matchResult = preg_match('/\As:(\d+):"/', substr($data, $start), $m);
                if ($matchResult === 0 || $matchResult === false) {
                    return null;
                }
                $strlen = (int)$m[1];
                $prefixLen = strlen('s:' . $m[1] . ':"');
                $contentStart = $start + $prefixLen;
                $contentEnd = $contentStart + $strlen;
                if ($contentEnd + 2 > $len) {
                    return null;
                }
                if (substr($data, $contentEnd, 2) !== '";') {
                    return null;
                }
                $serialized = substr($data, $start, $contentEnd + 2 - $start);
                return [$serialized, $contentEnd + 2];

            case 'a': // a:len:{...}
            case 'O': // O:strlen:"Class":len:{...}
            case 'C': // C:strlen:"Class":len:serialized-data
                $bracePos = strpos($data, '{', $start);
                if ($bracePos === false) {
                    return null;
                }

                $pos = $bracePos;
                $depth = 0;
                while ($pos < $len) {
                    $char = $data[$pos];
                    if ($char === '{') {
                        $depth++;
                    } elseif ($char === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $next = $pos + 1;
                            $serialized = substr($data, $start, $next - $start);
                            return [$serialized, $next];
                        }
                    } elseif ($char === '"') {
                        $pos++;
                        while ($pos < $len) {
                            if ($data[$pos] === '\\') {
                                $pos += 2;
                                continue;
                            }
                            if ($data[$pos] === '"') {
                                break;
                            }
                            $pos++;
                        }
                    }
                    $pos++;
                }
                return null;

            default:
                return null;
        }
    }
}
