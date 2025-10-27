<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Serializer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Exception\SessionDataException;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializer;

class PhpSerializerTest extends TestCase
{
    private PhpSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new PhpSerializer();
    }


    public function testGetnamereturnsphp(): void
    {
        self::assertSame('php', $this->serializer->getName());
    }


    public function testEncodeemptyarrayreturnsemptystring(): void
    {
        $result = $this->serializer->encode([]);
        self::assertSame('', $result);
    }


    public function testEncodesimplestringvalue(): void
    {
        $data = ['foo' => 'bar'];
        $result = $this->serializer->encode($data);
        self::assertSame('foo|s:3:"bar";', $result);
    }


    public function testEncodeintegervalue(): void
    {
        $data = ['test' => 123];
        $result = $this->serializer->encode($data);
        self::assertSame('test|i:123;', $result);
    }


    public function testEncodemultiplevalues(): void
    {
        $data = ['foo' => 'bar', 'test' => 123];
        $result = $this->serializer->encode($data);
        self::assertSame('foo|s:3:"bar";test|i:123;', $result);
    }


    public function testEncodenestedarray(): void
    {
        $data = ['nested' => ['a' => 1, 'b' => 2]];
        $result = $this->serializer->encode($data);
        self::assertSame('nested|a:2:{s:1:"a";i:1;s:1:"b";i:2;}', $result);
    }


    public function testEncodenullvalue(): void
    {
        $data = ['null_value' => null];
        $result = $this->serializer->encode($data);
        self::assertSame('null_value|N;', $result);
    }


    public function testEncodebooleanvalues(): void
    {
        $data = ['true_val' => true, 'false_val' => false];
        $result = $this->serializer->encode($data);
        self::assertSame('true_val|b:1;false_val|b:0;', $result);
    }


    public function testEncodefloatvalue(): void
    {
        $data = ['float_val' => 3.14];
        $result = $this->serializer->encode($data);
        self::assertStringStartsWith('float_val|d:3.14', $result);
    }


    public function testDecodeemptystringreturnsemptyarray(): void
    {
        $result = $this->serializer->decode('');
        self::assertSame([], $result);
    }


    public function testDecodesimplestringvalue(): void
    {
        $data = 'foo|s:3:"bar";';
        $result = $this->serializer->decode($data);
        self::assertSame(['foo' => 'bar'], $result);
    }


    public function testDecodeintegervalue(): void
    {
        $data = 'test|i:123;';
        $result = $this->serializer->decode($data);
        self::assertSame(['test' => 123], $result);
    }


    public function testDecodemultiplevalues(): void
    {
        $data = 'foo|s:3:"bar";test|i:123;';
        $result = $this->serializer->decode($data);
        self::assertSame(['foo' => 'bar', 'test' => 123], $result);
    }


    public function testDecodenestedarray(): void
    {
        $data = 'nested|a:2:{s:1:"a";i:1;s:1:"b";i:2;}';
        $result = $this->serializer->decode($data);
        self::assertSame(['nested' => ['a' => 1, 'b' => 2]], $result);
    }


    public function testDecodenullvalue(): void
    {
        $data = 'null_value|N;';
        $result = $this->serializer->decode($data);
        self::assertSame(['null_value' => null], $result);
    }


    public function testDecodebooleanvalues(): void
    {
        $data = 'true_val|b:1;false_val|b:0;';
        $result = $this->serializer->decode($data);
        self::assertSame(['true_val' => true, 'false_val' => false], $result);
    }


    public function testDecodefloatvalue(): void
    {
        $data = 'float_val|d:3.14;';
        $result = $this->serializer->decode($data);
        self::assertEqualsWithDelta(['float_val' => 3.14], $result, 0.001);
    }


    public function testDecodewithtrailingwhitespace(): void
    {
        $data = 'foo|s:3:"bar";  ';
        $result = $this->serializer->decode($data);
        self::assertSame(['foo' => 'bar'], $result);
    }


    public function testDecodethrowsexceptionformalformeddatamissingpipe(): void
    {
        $this->expectException(SessionDataException::class);
        $this->expectExceptionMessage('Malformed session data: missing "|"');
        $this->serializer->decode('foo');
    }


    public function testDecodethrowsexceptionformalformedserializedvalue(): void
    {
        $this->expectException(SessionDataException::class);
        $this->expectExceptionMessage('Malformed serialized value');
        $this->serializer->decode('foo|invalid');
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


    public function testDecodecomplexnestedstructure(): void
    {
        $data = 'user|a:3:{s:4:"name";s:4:"John";s:3:"age";i:30;s:5:"roles";a:2:{i:0;s:5:"admin";i:1;s:4:"user";}}';
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


    public function testEncodewithspecialcharactersinstring(): void
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


    public function testDecodestringwithpipecharacterinvalue(): void
    {
        $data = 'key|s:10:"val|ue|123";';
        $result = $this->serializer->decode($data);
        self::assertSame(['key' => 'val|ue|123'], $result);
    }


    public function testEncodeintegerkeyconvertstostring(): void
    {
        $data = ['key0' => 'zero', 'key1' => 'one'];
        $encoded = $this->serializer->encode($data);
        self::assertStringContainsString('key0|', $encoded);
        self::assertStringContainsString('key1|', $encoded);
    }
}
