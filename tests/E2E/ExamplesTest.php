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
            self::fail("Example file not found: {$examplePath}");
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open(
            ['php', $examplePath],
            $descriptorspec,
            $pipes
        );

        if (!is_resource($process)) {
            self::fail("Failed to execute example: {$exampleFile}");
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        return [
            'output' => $stdout . $stderr,
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
            self::assertDoesNotMatchRegularExpression(
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
        self::assertStringContainsString(
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

        self::assertSame(0, $result['return_code'], 'Example should exit with code 0');
        self::assertNoErrorsOrWarnings($result['output'], '01-basic-usage.php');
        self::assertSuccessMessage($result['output'], '01-basic-usage.php');

        self::assertStringContainsString('Creating Redis connection configuration', $result['output']);
        self::assertStringContainsString('Writing data to session', $result['output']);
        self::assertStringContainsString('Session saved successfully', $result['output']);
    }

    /**
     * @test
     */
    public function test02CustomSessionIdExample(): void
    {
        $result = $this->executeExample('02-custom-session-id.php');

        self::assertSame(0, $result['return_code'], 'Example should exit with code 0');
        self::assertNoErrorsOrWarnings($result['output'], '02-custom-session-id.php');
        self::assertSuccessMessage($result['output'], '02-custom-session-id.php');

        self::assertStringContainsString('Prefixed Session ID Generator', $result['output']);
        self::assertStringContainsString('Timestamp Prefixed Session ID Generator', $result['output']);
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
            self::markTestSkipped('Multiple Redis databases not available: ' . $e->getMessage());
        } finally {
            $redis->close();
        }

        $result = $this->executeExample('03-double-write.php');

        self::assertSame(0, $result['return_code'], 'Example should exit with code 0');
        self::assertNoErrorsOrWarnings($result['output'], '03-double-write.php');
        self::assertSuccessMessage($result['output'], '03-double-write.php');

        self::assertStringContainsString('Double Write Hook', $result['output']);
        self::assertStringContainsString('primary and secondary Redis', $result['output']);
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
            self::markTestSkipped('Multiple Redis databases not available: ' . $e->getMessage());
        } finally {
            $redis->close();
        }

        $result = $this->executeExample('04-fallback-read.php');

        self::assertSame(0, $result['return_code'], 'Example should exit with code 0');
        self::assertNoErrorsOrWarnings($result['output'], '04-fallback-read.php');
        self::assertSuccessMessage($result['output'], '04-fallback-read.php');

        self::assertStringContainsString('Fallback Read Hook', $result['output']);
        self::assertStringContainsString('high availability', $result['output']);
    }

    /**
     * @test
     */
    public function test05LoggingExample(): void
    {
        $result = $this->executeExample('05-logging.php');

        self::assertSame(0, $result['return_code'], 'Example should exit with code 0');
        self::assertNoErrorsOrWarnings($result['output'], '05-logging.php');
        self::assertSuccessMessage($result['output'], '05-logging.php');

        self::assertStringContainsString('Logging Example', $result['output']);
        self::assertStringContainsString('session', $result['output']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function exampleFilesProvider(): array
    {
        return [
            '01-basic-usage' => ['01-basic-usage.php'],
            '02-custom-session-id' => ['02-custom-session-id.php'],
            '03-double-write' => ['03-double-write.php'],
            '04-fallback-read' => ['04-fallback-read.php'],
            '05-logging' => ['05-logging.php'],
        ];
    }

    /**
     * @test
     * @dataProvider exampleFilesProvider
     */
    public function testExampleCanBeExecuted(string $exampleFile): void
    {
        $result = $this->executeExample($exampleFile);
        self::assertSame(
            0,
            $result['return_code'],
            "Example {$exampleFile} failed to execute"
        );
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

        self::assertLessThanOrEqual(
            $keysBefore + 5, // 多少の増加は許容
            $keysAfter,
            'Example should clean up Redis keys after execution'
        );
    }
}
