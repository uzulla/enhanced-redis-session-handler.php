<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Hook\EmptySessionFilter;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\PsrTestLogger;

class EmptySessionFilterTest extends TestCase
{
    private PsrTestLogger $logger;
    private EmptySessionFilter $filter;

    protected function setUp(): void
    {
        $this->logger = new PsrTestLogger();
        $this->filter = new EmptySessionFilter($this->logger);
    }

    public function testShouldWriteReturnsFalseForEmptyArray(): void
    {
        $sessionId = 'test_session_id_12345678';
        $data = [];

        $result = $this->filter->shouldWrite($sessionId, $data);

        self::assertFalse($result, 'shouldWrite should return false for empty data array');
    }

    public function testShouldWriteReturnsTrueForNonEmptyArray(): void
    {
        $sessionId = 'test_session_id_12345678';
        $data = ['user_id' => 123, 'username' => 'testuser'];

        $result = $this->filter->shouldWrite($sessionId, $data);

        self::assertTrue($result, 'shouldWrite should return true for non-empty data array');
    }

    public function testShouldWriteLogsWhenDataIsEmpty(): void
    {
        $sessionId = 'test_session_id_12345678';
        $data = [];

        $this->filter->shouldWrite($sessionId, $data);

        self::assertTrue($this->logger->hasDebugRecords(), 'Logger should have debug records');

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Empty session detected, write operation cancelled') {
                $found = true;
                self::assertArrayHasKey('context', $record);
                $context = $record['context'];
                self::assertArrayHasKey('session_id', $context);
                self::assertArrayHasKey('data_empty', $context);
                self::assertTrue($context['data_empty']);
                $maskedId = $context['session_id'];
                self::assertIsString($maskedId);
                self::assertStringContainsString('*', $maskedId);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message for empty session not found');
    }

    public function testShouldWriteLogsWhenDataIsNotEmpty(): void
    {
        $sessionId = 'test_session_id_12345678';
        $data = ['user_id' => 123];

        $this->filter->shouldWrite($sessionId, $data);

        self::assertTrue($this->logger->hasDebugRecords(), 'Logger should have debug records');

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Session has data, write operation allowed') {
                $found = true;
                self::assertArrayHasKey('context', $record);
                $context = $record['context'];
                self::assertArrayHasKey('session_id', $context);
                self::assertArrayHasKey('data_empty', $context);
                self::assertFalse($context['data_empty']);
                self::assertArrayHasKey('data_keys', $context);
                $dataKeys = $context['data_keys'];
                self::assertIsArray($dataKeys);
                self::assertSame(['user_id'], $dataKeys);
                $maskedId = $context['session_id'];
                self::assertIsString($maskedId);
                self::assertStringContainsString('*', $maskedId);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message for non-empty session not found');
    }

    public function testShouldWriteHandlesMultipleDataKeys(): void
    {
        $sessionId = 'test_session_id_12345678';
        $data = [
            'user_id' => 123,
            'username' => 'testuser',
            'email' => 'test@example.com',
            'role' => 'admin',
        ];

        $result = $this->filter->shouldWrite($sessionId, $data);

        self::assertTrue($result, 'shouldWrite should return true for data with multiple keys');

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Session has data, write operation allowed') {
                $found = true;
                $context = $record['context'];
                self::assertArrayHasKey('data_keys', $context);
                $dataKeys = $context['data_keys'];
                self::assertIsArray($dataKeys);
                self::assertCount(4, $dataKeys);
                self::assertContains('user_id', $dataKeys);
                self::assertContains('username', $dataKeys);
                self::assertContains('email', $dataKeys);
                self::assertContains('role', $dataKeys);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message not found');
    }

    public function testShouldWriteHandlesShortSessionId(): void
    {
        $sessionId = 'short';
        $data = ['key' => 'value'];

        $result = $this->filter->shouldWrite($sessionId, $data);

        self::assertTrue($result, 'shouldWrite should return true for non-empty data');

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Session has data, write operation allowed') {
                $found = true;
                $context = $record['context'];
                self::assertArrayHasKey('session_id', $context);
                self::assertSame($sessionId, $context['session_id']);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message not found');
    }
}
