<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConfigurationException;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializer;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;

class SessionSerializeHandlerTest extends TestCase
{
    private RedisConnection $connection;
    private string $testSessionId;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for this test');
        }

        $hostEnv = getenv('SESSION_REDIS_HOST');
        $portEnv = getenv('SESSION_REDIS_PORT');

        $host = $hostEnv !== false ? $hostEnv : '127.0.0.1';
        $port = $portEnv !== false ? (int)$portEnv : 6379;

        $config = new RedisConnectionConfig(
            $host,
            $port,
            2.5,
            null,
            0,
            'test_serialize_'
        );

        $this->connection = new RedisConnection(new \Redis(), $config, new \Psr\Log\NullLogger());
        $this->connection->connect();

        $this->testSessionId = 'test_session_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->delete($this->testSessionId);
        }
    }


    /**
     * @runInSeparateProcess
     */
    public function testOpenwithphpserializehandlercreatescorrectserializer(): void
    {
        $originalHandler = ini_get('session.serialize_handler');
        ini_set('session.serialize_handler', 'php_serialize');

        try {
            $handler = new RedisSessionHandler(
                $this->connection,
                new PhpSerializeSerializer(),
                new RedisSessionHandlerOptions()
            );

            $result = $handler->open('', '');
            self::assertTrue($result);

            $sessionData = ['foo' => 'bar', 'test' => 123];
            $encoded = serialize($sessionData);

            $writeResult = $handler->write($this->testSessionId, $encoded);
            self::assertTrue($writeResult);

            $readData = $handler->read($this->testSessionId);
            self::assertSame($encoded, $readData);
        } finally {
            if ($originalHandler !== false) {
                ini_set('session.serialize_handler', $originalHandler);
            }
        }
    }


    /**
     * @runInSeparateProcess
     */
    public function testOpenwithphphandlercreatescorrectserializer(): void
    {
        $originalHandler = ini_get('session.serialize_handler');
        ini_set('session.serialize_handler', 'php');

        try {
            $handler = new RedisSessionHandler(
                $this->connection,
                new PhpSerializer(),
                new RedisSessionHandlerOptions()
            );

            $result = $handler->open('', '');
            self::assertTrue($result);

            $phpFormatData = 'foo|s:3:"bar";test|i:123;';

            $writeResult = $handler->write($this->testSessionId, $phpFormatData);
            self::assertTrue($writeResult);

            $readData = $handler->read($this->testSessionId);
            self::assertSame($phpFormatData, $readData);
        } finally {
            if ($originalHandler !== false) {
                ini_set('session.serialize_handler', $originalHandler);
            }
        }
    }


    /**
     * @runInSeparateProcess
     */
    public function testOpenwithunsupportedhandlerthrowsexception(): void
    {
        $originalHandler = ini_get('session.serialize_handler');


        $supportedHandlers = ['php', 'php_serialize'];
        if (in_array($originalHandler, $supportedHandlers, true)) {
            self::markTestSkipped('Cannot test unsupported handler - current handler is supported');
        }

        try {
            $handler = new RedisSessionHandler(
                $this->connection,
                new PhpSerializeSerializer(),
                new RedisSessionHandlerOptions()
            );

            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessage('Serializer mismatch');

            $handler->open('', '');
        } finally {
            if ($originalHandler !== false) {
                ini_set('session.serialize_handler', $originalHandler);
            }
        }
    }


    /**
     * @runInSeparateProcess
     */
    public function testWritewithphpformatpreservesdatacorrectly(): void
    {
        $originalHandler = ini_get('session.serialize_handler');
        ini_set('session.serialize_handler', 'php');

        try {
            $handler = new RedisSessionHandler(
                $this->connection,
                new PhpSerializer(),
                new RedisSessionHandlerOptions()
            );

            $handler->open('', '');

            $phpFormatData = 'user|a:3:{s:4:"name";s:4:"John";s:3:"age";i:30;s:5:"roles";a:2:{i:0;s:5:"admin";i:1;s:4:"user";}}';

            $writeResult = $handler->write($this->testSessionId, $phpFormatData);
            self::assertTrue($writeResult);

            $readData = $handler->read($this->testSessionId);
            self::assertSame($phpFormatData, $readData);

            $redisData = $this->connection->get($this->testSessionId);
            self::assertSame($phpFormatData, $redisData);
        } finally {
            if ($originalHandler !== false) {
                ini_set('session.serialize_handler', $originalHandler);
            }
        }
    }


    /**
     * @runInSeparateProcess
     */
    public function testWritewithphpserializeformatpreservesdatacorrectly(): void
    {
        $originalHandler = ini_get('session.serialize_handler');
        ini_set('session.serialize_handler', 'php_serialize');

        try {
            $handler = new RedisSessionHandler(
                $this->connection,
                new PhpSerializeSerializer(),
                new RedisSessionHandlerOptions()
            );

            $handler->open('', '');

            $sessionData = [
                'user' => [
                    'name' => 'John',
                    'age' => 30,
                    'roles' => ['admin', 'user'],
                ],
            ];
            $serialized = serialize($sessionData);

            $writeResult = $handler->write($this->testSessionId, $serialized);
            self::assertTrue($writeResult);

            $readData = $handler->read($this->testSessionId);
            self::assertSame($serialized, $readData);

            $redisData = $this->connection->get($this->testSessionId);
            self::assertSame($serialized, $redisData);
        } finally {
            if ($originalHandler !== false) {
                ini_set('session.serialize_handler', $originalHandler);
            }
        }
    }


    /**
     * @runInSeparateProcess
     */
    public function testWritewithmalformedphpformatdatalogswarningandcontinues(): void
    {
        $originalHandler = ini_get('session.serialize_handler');
        ini_set('session.serialize_handler', 'php');

        try {
            $handler = new RedisSessionHandler(
                $this->connection,
                new PhpSerializer(),
                new RedisSessionHandlerOptions()
            );

            $handler->open('', '');

            $malformedData = 'invalid|malformed';

            $writeResult = $handler->write($this->testSessionId, $malformedData);
            self::assertTrue($writeResult);

            $readData = $handler->read($this->testSessionId);
            self::assertSame('', $readData);
        } finally {
            if ($originalHandler !== false) {
                ini_set('session.serialize_handler', $originalHandler);
            }
        }
    }


    /**
     * @runInSeparateProcess
     */
    public function testWriteemptysessionwithphpformat(): void
    {
        $originalHandler = ini_get('session.serialize_handler');
        ini_set('session.serialize_handler', 'php');

        try {
            $handler = new RedisSessionHandler(
                $this->connection,
                new PhpSerializer(),
                new RedisSessionHandlerOptions()
            );

            $handler->open('', '');

            $emptyData = '';

            $writeResult = $handler->write($this->testSessionId, $emptyData);
            self::assertTrue($writeResult);

            $readData = $handler->read($this->testSessionId);
            self::assertSame('', $readData);
        } finally {
            if ($originalHandler !== false) {
                ini_set('session.serialize_handler', $originalHandler);
            }
        }
    }


    /**
     * @runInSeparateProcess
     */
    public function testWritewithunicodedatainphpformat(): void
    {
        $originalHandler = ini_get('session.serialize_handler');
        ini_set('session.serialize_handler', 'php');

        try {
            $handler = new RedisSessionHandler(
                $this->connection,
                new PhpSerializer(),
                new RedisSessionHandlerOptions()
            );

            $handler->open('', '');

            $phpFormatData = 'message|s:21:"こんにちは世界";';

            $writeResult = $handler->write($this->testSessionId, $phpFormatData);
            self::assertTrue($writeResult);

            $readData = $handler->read($this->testSessionId);
            self::assertSame($phpFormatData, $readData);
        } finally {
            if ($originalHandler !== false) {
                ini_set('session.serialize_handler', $originalHandler);
            }
        }
    }


    /**
     * @runInSeparateProcess
     */
    public function testSwitchingserializehandlerbetweenwritesworkscorrectly(): void
    {
        $originalHandler = ini_get('session.serialize_handler');

        try {
            ini_set('session.serialize_handler', 'php_serialize');
            $handler1 = new RedisSessionHandler(
                $this->connection,
                new PhpSerializeSerializer(),
                new RedisSessionHandlerOptions()
            );
            $handler1->open('', '');

            $data1 = serialize(['foo' => 'bar']);
            $handler1->write($this->testSessionId, $data1);

            $sessionId2 = $this->testSessionId . '_2';
            ini_set('session.serialize_handler', 'php');
            $handler2 = new RedisSessionHandler(
                $this->connection,
                new PhpSerializer(),
                new RedisSessionHandlerOptions()
            );
            $handler2->open('', '');

            $data2 = 'test|i:123;';
            $handler2->write($sessionId2, $data2);

            $read1 = $handler1->read($this->testSessionId);
            self::assertSame($data1, $read1);

            $read2 = $handler2->read($sessionId2);
            self::assertSame($data2, $read2);

            $this->connection->delete($sessionId2);
        } finally {
            if ($originalHandler !== false) {
                ini_set('session.serialize_handler', $originalHandler);
            }
        }
    }
}
