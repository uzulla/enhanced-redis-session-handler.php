<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Serializer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;

class PhpSerializeSerializerTest extends TestCase
{
    private PhpSerializeSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new PhpSerializeSerializer();
    }


    public function testGetnamereturnsphpserialize(): void
    {
        self::assertSame('php_serialize', $this->serializer->getName());
    }


    public function testEncodeemptyarray(): void
    {
        $result = $this->serializer->encode([]);
        self::assertSame('a:0:{}', $result);
    }


    public function testEncodesimplearray(): void
    {
        $data = ['foo' => 'bar'];
        $result = $this->serializer->encode($data);
        self::assertSame('a:1:{s:3:"foo";s:3:"bar";}', $result);
    }


    public function testEncodemultiplevalues(): void
    {
        $data = ['foo' => 'bar', 'test' => 123];
        $result = $this->serializer->encode($data);
        self::assertSame('a:2:{s:3:"foo";s:3:"bar";s:4:"test";i:123;}', $result);
    }


    public function testEncodenestedarray(): void
    {
        $data = ['nested' => ['a' => 1, 'b' => 2]];
        $result = $this->serializer->encode($data);
        self::assertSame('a:1:{s:6:"nested";a:2:{s:1:"a";i:1;s:1:"b";i:2;}}', $result);
    }


    public function testDecodeemptystringreturnsemptyarray(): void
    {
        $result = $this->serializer->decode('');
        self::assertSame([], $result);
    }


    public function testDecodeemptyarray(): void
    {
        $data = 'a:0:{}';
        $result = $this->serializer->decode($data);
        self::assertSame([], $result);
    }


    public function testDecodesimplearray(): void
    {
        $data = 'a:1:{s:3:"foo";s:3:"bar";}';
        $result = $this->serializer->decode($data);
        self::assertSame(['foo' => 'bar'], $result);
    }


    public function testDecodemultiplevalues(): void
    {
        $data = 'a:2:{s:3:"foo";s:3:"bar";s:4:"test";i:123;}';
        $result = $this->serializer->decode($data);
        self::assertSame(['foo' => 'bar', 'test' => 123], $result);
    }


    public function testDecodenestedarray(): void
    {
        $data = 'a:1:{s:6:"nested";a:2:{s:1:"a";i:1;s:1:"b";i:2;}}';
        $result = $this->serializer->decode($data);
        self::assertSame(['nested' => ['a' => 1, 'b' => 2]], $result);
    }


    public function testDecodethrowsexceptionforinvaliddata(): void
    {
        $this->expectException(SessionDataException::class);
        $this->expectExceptionMessage('Failed to unserialize session data');
        $this->serializer->decode('invalid data');
    }


    public function testDecodethrowsexceptionfornonarraydata(): void
    {
        $this->expectException(SessionDataException::class);
        $this->expectExceptionMessage('Session data is not an array');
        $this->serializer->decode('s:4:"test";');
    }


    public function testEncodedecoderoundtrip(): void
    {
        $original = [
            'string' => 'hello world',
            'int' => 42,
            'float' => 3.14159,
            'bool_true' => true,
            'bool_false' => false,
            'null' => null,
            'array' => ['nested' => 'value'],
        ];

        $encoded = $this->serializer->encode($original);
        $decoded = $this->serializer->decode($encoded);

        self::assertEquals($original, $decoded);
    }


    public function testEncodewithspecialcharacters(): void
    {
        $data = ['special' => "line1\nline2\ttab"];
        $encoded = $this->serializer->encode($data);
        $decoded = $this->serializer->decode($encoded);

        self::assertSame($data, $decoded);
    }


    public function testEncodewithunicodecharacters(): void
    {
        $data = ['unicode' => '日本語テスト'];
        $encoded = $this->serializer->encode($data);
        $decoded = $this->serializer->decode($encoded);

        self::assertSame($data, $decoded);
    }


    public function testDecodecomplexnestedstructure(): void
    {
        $data = 'a:1:{s:4:"user";a:3:{s:4:"name";s:4:"John";s:3:"age";i:30;s:5:"roles";a:2:{i:0;s:5:"admin";i:1;s:4:"user";}}}';
        $result = $this->serializer->decode($data);

        $expected = [
            'user' => [
                'name' => 'John',
                'age' => 30,
                'roles' => ['admin', 'user'],
            ],
        ];

        self::assertSame($expected, $result);
    }


    public function testDecodebooleanfalsevalue(): void
    {
        $data = 'b:0;';
        $this->expectException(SessionDataException::class);
        $this->expectExceptionMessage('Session data is not an array');
        $this->serializer->decode($data);
    }
}
