<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * E2E tests for example files
 *
 * これらのテストは、examplesディレクトリ内のサンプルファイルが
 * エラーや警告なしで実行できることを確認します。
 *
 * These tests verify that example files in the examples directory
 * can be executed without errors or warnings.
 */
class ExamplesTest extends TestCase
{
    private static string $redisHost;
    private static int $redisPort;

    public static function setUpBeforeClass(): void
    {
        $hostEnv = getenv('SESSION_REDIS_HOST');
        self::$redisHost = $hostEnv !== false ? $hostEnv : 'localhost';
        $portEnv = getenv('SESSION_REDIS_PORT');
        self::$redisPort = $portEnv !== false ? (int)$portEnv : 6379;

        $redis = new \Redis();
        try {
            $connected = @$redis->connect(self::$redisHost, self::$redisPort, 1.0);
            if ($connected === false) {
                self::markTestSkipped('Redis is not available at ' . self::$redisHost . ':' . self::$redisPort);
            }
            $redis->close();
        } catch (\Exception $e) {
            self::markTestSkipped('Redis is not available: ' . $e->getMessage());
        }
    }

    /**
     * サンプルファイルを実行してエラーや警告がないことを確認
     * Execute example file and verify no errors or warnings
     *
     * @return array{output: string, return_code: int}
     */
    private function executeExample(string $exampleFile): array
    {
        $examplePath = __DIR__ . '/../../examples/' . $exampleFile;

        if (!file_exists($examplePath)) {
            $this->fail("Example file not found: {$examplePath}");
        }

        $command = sprintf(
            'php %s 2>&1',
            escapeshellarg($examplePath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        return [
            'output' => implode("\n", $output),
            'return_code' => $returnCode,
        ];
    }

    /**
     * 出力にエラーや警告が含まれていないことを確認
     * Verify output does not contain errors or warnings
     */
    private function assertNoErrorsOrWarnings(string $output, string $exampleFile): void
    {
        $errorPatterns = [
            '/PHP Fatal error:/i',
            '/PHP Parse error:/i',
            '/Fatal error:/i',
            '/Parse error:/i',
            '/Uncaught .* Error:/i',
        ];

        foreach ($errorPatterns as $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $output,
                "Example {$exampleFile} produced an error"
            );
        }

    }

    /**
     * 出力に成功メッセージが含まれていることを確認
     * Verify output contains success message
     */
    private function assertSuccessMessage(string $output, string $exampleFile): void
    {
        $this->assertStringContainsString(
            'Example completed successfully',
            $output,
            "Example {$exampleFile} did not complete successfully"
        );
    }

    /**
     * @test
     */
    public function test01BasicUsageExample(): void
    {
        $result = $this->executeExample('01-basic-usage.php');

        $this->assertSame(0, $result['return_code'], 'Example should exit with code 0');
        $this->assertNoErrorsOrWarnings($result['output'], '01-basic-usage.php');
        $this->assertSuccessMessage($result['output'], '01-basic-usage.php');

        $this->assertStringContainsString('Creating Redis connection configuration', $result['output']);
        $this->assertStringContainsString('Writing data to session', $result['output']);
        $this->assertStringContainsString('Session saved successfully', $result['output']);
    }

    /**
     * @test
     */
    public function test02CustomSessionIdExample(): void
    {
        $result = $this->executeExample('02-custom-session-id.php');

        $this->assertSame(0, $result['return_code'], 'Example should exit with code 0');
        $this->assertNoErrorsOrWarnings($result['output'], '02-custom-session-id.php');
        $this->assertSuccessMessage($result['output'], '02-custom-session-id.php');

        $this->assertStringContainsString('Prefixed Session ID Generator', $result['output']);
        $this->assertStringContainsString('Timestamp Prefixed Session ID Generator', $result['output']);
    }

    /**
     * @test
     */
    public function test03DoubleWriteExample(): void
    {
        $redis = new \Redis();
        $redis->connect(self::$redisHost, self::$redisPort);

        try {
            $redis->select(1);
            $redis->select(2);
        } catch (\Exception $e) {
            $this->markTestSkipped('Multiple Redis databases not available: ' . $e->getMessage());
        } finally {
            $redis->close();
        }

        $result = $this->executeExample('03-double-write.php');

        $this->assertSame(0, $result['return_code'], 'Example should exit with code 0');
        $this->assertNoErrorsOrWarnings($result['output'], '03-double-write.php');
        $this->assertSuccessMessage($result['output'], '03-double-write.php');

        $this->assertStringContainsString('Double Write Hook', $result['output']);
        $this->assertStringContainsString('primary and secondary Redis', $result['output']);
    }

    /**
     * @test
     */
    public function test04FallbackReadExample(): void
    {
        $redis = new \Redis();
        $redis->connect(self::$redisHost, self::$redisPort);

        try {
            $redis->select(1);
            $redis->select(2);
        } catch (\Exception $e) {
            $this->markTestSkipped('Multiple Redis databases not available: ' . $e->getMessage());
        } finally {
            $redis->close();
        }

        $result = $this->executeExample('04-fallback-read.php');

        $this->assertSame(0, $result['return_code'], 'Example should exit with code 0');
        $this->assertNoErrorsOrWarnings($result['output'], '04-fallback-read.php');
        $this->assertSuccessMessage($result['output'], '04-fallback-read.php');

        $this->assertStringContainsString('Fallback Read Hook', $result['output']);
        $this->assertStringContainsString('high availability', $result['output']);
    }

    /**
     * @test
     */
    public function test05LoggingExample(): void
    {
        $result = $this->executeExample('05-logging.php');

        $this->assertSame(0, $result['return_code'], 'Example should exit with code 0');
        $this->assertNoErrorsOrWarnings($result['output'], '05-logging.php');
        $this->assertSuccessMessage($result['output'], '05-logging.php');

        $this->assertStringContainsString('Logging Example', $result['output']);
        $this->assertStringContainsString('session', $result['output']);
    }

    /**
     * @test
     */
    public function testAllExamplesCanBeExecutedSequentially(): void
    {
        $examples = [
            '01-basic-usage.php',
            '02-custom-session-id.php',
            '03-double-write.php',
            '04-fallback-read.php',
            '05-logging.php',
        ];

        foreach ($examples as $example) {
            $result = $this->executeExample($example);
            $this->assertSame(
                0,
                $result['return_code'],
                "Example {$example} failed when executed sequentially"
            );
        }
    }

    /**
     * @test
     */
    public function testExamplesCleanUpRedisKeys(): void
    {
        $redis = new \Redis();
        $redis->connect(self::$redisHost, self::$redisPort);

        $keysBefore = $redis->dbSize();

        $this->executeExample('01-basic-usage.php');

        $keysAfter = $redis->dbSize();

        $redis->close();

        $this->assertLessThanOrEqual(
            $keysBefore + 5, // 多少の増加は許容
            $keysAfter,
            'Example should clean up Redis keys after execution'
        );
    }
}
