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
                self::assertArrayNotHasKey('data', $context);
                $maskedId = $context['session_id'];
                self::assertIsString($maskedId);
                self::assertStringContainsString('...', $maskedId);
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
                self::assertArrayHasKey('data', $context);
                self::assertSame($data, $context['data']);
                $maskedId = $context['session_id'];
                self::assertIsString($maskedId);
                self::assertStringContainsString('...', $maskedId);
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
                self::assertArrayHasKey('data', $context);
                self::assertSame($data, $context['data']);
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
                self::assertSame('...hort', $context['session_id']);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message not found');
    }

    public function testWasLastWriteEmptyInitialState(): void
    {
        $result = $this->filter->wasLastWriteEmpty();

        self::assertFalse($result, 'wasLastWriteEmpty should return false initially');
    }

    public function testWasLastWriteEmptyAfterEmptyWrite(): void
    {
        $sessionId = 'test_session_id_12345678';
        $data = [];

        $this->filter->shouldWrite($sessionId, $data);
        $result = $this->filter->wasLastWriteEmpty();

        self::assertTrue($result, 'wasLastWriteEmpty should return true after empty write');
    }

    public function testWasLastWriteEmptyAfterNonEmptyWrite(): void
    {
        $sessionId = 'test_session_id_12345678';
        $data = ['user_id' => 123];

        $this->filter->shouldWrite($sessionId, $data);
        $result = $this->filter->wasLastWriteEmpty();

        self::assertFalse($result, 'wasLastWriteEmpty should return false after non-empty write');
    }

    public function testWasLastWriteEmptyStateTransitionEmptyToNonEmpty(): void
    {
        $sessionId = 'test_session_id_12345678';

        $this->filter->shouldWrite($sessionId, []);
        self::assertTrue($this->filter->wasLastWriteEmpty(), 'State should be true after empty write');

        $this->filter->shouldWrite($sessionId, ['user_id' => 123]);
        self::assertFalse($this->filter->wasLastWriteEmpty(), 'State should be false after non-empty write');
    }

    public function testWasLastWriteEmptyStateTransitionNonEmptyToEmpty(): void
    {
        $sessionId = 'test_session_id_12345678';

        $this->filter->shouldWrite($sessionId, ['user_id' => 123]);
        self::assertFalse($this->filter->wasLastWriteEmpty(), 'State should be false after non-empty write');

        $this->filter->shouldWrite($sessionId, []);
        self::assertTrue($this->filter->wasLastWriteEmpty(), 'State should be true after empty write');
    }

    public function testWasLastWriteEmptyConsecutiveEmptyCalls(): void
    {
        $sessionId = 'test_session_id_12345678';

        $this->filter->shouldWrite($sessionId, []);
        self::assertTrue($this->filter->wasLastWriteEmpty(), 'State should be true after first empty write');

        $this->filter->shouldWrite($sessionId, []);
        self::assertTrue($this->filter->wasLastWriteEmpty(), 'State should remain true after second empty write');

        $this->filter->shouldWrite($sessionId, []);
        self::assertTrue($this->filter->wasLastWriteEmpty(), 'State should remain true after third empty write');
    }

    public function testWasLastWriteEmptyConsecutiveNonEmptyCalls(): void
    {
        $sessionId = 'test_session_id_12345678';

        $this->filter->shouldWrite($sessionId, ['key1' => 'value1']);
        self::assertFalse($this->filter->wasLastWriteEmpty(), 'State should be false after first non-empty write');

        $this->filter->shouldWrite($sessionId, ['key2' => 'value2']);
        self::assertFalse($this->filter->wasLastWriteEmpty(), 'State should remain false after second non-empty write');

        $this->filter->shouldWrite($sessionId, ['key3' => 'value3']);
        self::assertFalse($this->filter->wasLastWriteEmpty(), 'State should remain false after third non-empty write');
    }
}
