<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\SessionId\UserSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\UserSessionHelper;

class UserSessionHelperTest extends TestCase
{
    /** @var UserSessionIdGenerator */
    private $generator;

    /** @var RedisConnection&MockObject */
    private $connection;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var UserSessionHelper */
    private $helper;

    protected function setUp(): void
    {
        $this->generator = new UserSessionIdGenerator();
        $this->connection = $this->createMock(RedisConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->helper = new UserSessionHelper(
            $this->generator,
            $this->connection,
            $this->logger
        );
    }

    public function testForceLogoutUser(): void
    {
        $userId = '123';
        $sessionKeys = ['user123_abc', 'user123_def', 'user123_ghi'];

        $this->connection->expects(static::once())
            ->method('keys')
            ->with('user123_*')
            ->willReturn($sessionKeys);

        $this->connection->expects(static::exactly(3))
            ->method('delete')
            ->willReturn(true);

        $deletedCount = $this->helper->forceLogoutUser($userId);

        self::assertSame(3, $deletedCount);
    }

    public function testForceLogoutUserWithNoSessions(): void
    {
        $userId = '123';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with('user123_*')
            ->willReturn([]);

        $this->connection->expects(static::never())
            ->method('delete');

        $deletedCount = $this->helper->forceLogoutUser($userId);

        self::assertSame(0, $deletedCount);
    }

    public function testForceLogoutUserWithPartialFailure(): void
    {
        $userId = '123';
        $sessionKeys = ['user123_abc', 'user123_def', 'user123_ghi'];

        $this->connection->expects(static::once())
            ->method('keys')
            ->with('user123_*')
            ->willReturn($sessionKeys);

        // 2つ成功、1つ失敗
        $this->connection->expects(static::exactly(3))
            ->method('delete')
            ->willReturnOnConsecutiveCalls(true, false, true);

        $deletedCount = $this->helper->forceLogoutUser($userId);

        self::assertSame(2, $deletedCount);
    }

    public function testGetUserSessions(): void
    {
        $userId = '123';
        $sessionKeys = ['user123_abc', 'user123_def'];
        $sessionData1 = 'session_data_1';
        $sessionData2 = 'session_data_2';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with('user123_*')
            ->willReturn($sessionKeys);

        $this->connection->expects(static::exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($sessionData1, $sessionData2);

        $sessions = $this->helper->getUserSessions($userId);

        self::assertCount(2, $sessions);
        self::assertArrayHasKey('user123_abc', $sessions);
        self::assertArrayHasKey('user123_def', $sessions);

        self::assertSame('..._abc', $sessions['user123_abc']['session_id']);
        self::assertSame(strlen($sessionData1), $sessions['user123_abc']['data_size']);

        self::assertSame('..._def', $sessions['user123_def']['session_id']);
        self::assertSame(strlen($sessionData2), $sessions['user123_def']['data_size']);
    }

    public function testGetUserSessionsWithNoSessions(): void
    {
        $userId = '123';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with('user123_*')
            ->willReturn([]);

        $this->connection->expects(static::never())
            ->method('get');

        $sessions = $this->helper->getUserSessions($userId);

        self::assertCount(0, $sessions);
        self::assertSame([], $sessions);
    }

    public function testGetUserSessionsWithFailedGet(): void
    {
        $userId = '123';
        $sessionKeys = ['user123_abc', 'user123_def'];
        $sessionData = 'session_data';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with('user123_*')
            ->willReturn($sessionKeys);

        // 最初のgetは成功、2つ目は失敗
        $this->connection->expects(static::exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($sessionData, false);

        $sessions = $this->helper->getUserSessions($userId);

        // 失敗したセッションは含まれない
        self::assertCount(1, $sessions);
        self::assertArrayHasKey('user123_abc', $sessions);
        self::assertArrayNotHasKey('user123_def', $sessions);
    }

    public function testCountUserSessions(): void
    {
        $userId = '123';
        $sessionKeys = ['user123_abc', 'user123_def', 'user123_ghi'];

        $this->connection->expects(static::once())
            ->method('keys')
            ->with('user123_*')
            ->willReturn($sessionKeys);

        $count = $this->helper->countUserSessions($userId);

        self::assertSame(3, $count);
    }

    public function testCountUserSessionsWithNoSessions(): void
    {
        $userId = '123';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with('user123_*')
            ->willReturn([]);

        $count = $this->helper->countUserSessions($userId);

        self::assertSame(0, $count);
    }

    public function testForceLogoutUserWithDifferentUserId(): void
    {
        $userId = 'abc-123';
        $sessionKeys = ['userabc-123_xyz'];

        $this->connection->expects(static::once())
            ->method('keys')
            ->with('userabc-123_*')
            ->willReturn($sessionKeys);

        $this->connection->expects(static::once())
            ->method('delete')
            ->with('userabc-123_xyz')
            ->willReturn(true);

        $deletedCount = $this->helper->forceLogoutUser($userId);

        self::assertSame(1, $deletedCount);
    }

    public function testGetUserSessionsReturnsCorrectStructure(): void
    {
        $userId = '123';
        $sessionKeys = ['user123_abc'];
        $sessionData = 'test_data';

        $this->connection->expects(static::once())
            ->method('keys')
            ->willReturn($sessionKeys);

        $this->connection->expects(static::once())
            ->method('get')
            ->willReturn($sessionData);

        $sessions = $this->helper->getUserSessions($userId);

        self::assertArrayHasKey('user123_abc', $sessions);
        self::assertArrayHasKey('session_id', $sessions['user123_abc']);
        self::assertArrayHasKey('data_size', $sessions['user123_abc']);
    }

    /**
     * Redis特殊文字のエスケープテスト
     *
     * escapeRedisPattern()メソッドがRedis特殊文字を正しくエスケープすることを検証
     * 特殊文字: *, ?, [, ], \
     */
    public function testForceLogoutUserEscapesRedisSpecialCharacters(): void
    {
        // Redis特殊文字を含むユーザーID（実際のバリデーションでは拒否されるが、防御的プログラミングの観点でテスト）
        $userId = 'user*test';
        $escapedPattern = 'user' . 'user\\*test' . '_*';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with($escapedPattern)
            ->willReturn([]);

        $deletedCount = $this->helper->forceLogoutUser($userId);

        self::assertSame(0, $deletedCount);
    }

    /**
     * getUserSessions()でRedis特殊文字「?」をエスケープすることを検証
     *
     * 「?」は任意の1文字にマッチするRedis特殊文字であり、エスケープせずに使用すると
     * 意図しないパターンマッチが発生する可能性がある。このテストでは「?」が
     * 正しく「\?」にエスケープされることを確認する。
     */
    public function testGetUserSessionsEscapesRedisSpecialCharacters(): void
    {
        $userId = 'user?test';
        $escapedPattern = 'user' . 'user\\?test' . '_*';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with($escapedPattern)
            ->willReturn([]);

        $sessions = $this->helper->getUserSessions($userId);

        self::assertSame([], $sessions);
    }

    /**
     * countUserSessions()でRedis特殊文字「[」「]」をエスケープすることを検証
     *
     * 「[」「]」は文字クラスを表すRedis特殊文字であり、エスケープせずに使用すると
     * 文字セットのパターンマッチとして解釈されてしまう。このテストでは
     * 「[」「]」が正しく「\[」「\]」にエスケープされることを確認する。
     */
    public function testCountUserSessionsEscapesRedisSpecialCharacters(): void
    {
        $userId = 'user[test]';
        $escapedPattern = 'user' . 'user\\[test\\]' . '_*';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with($escapedPattern)
            ->willReturn([]);

        $count = $this->helper->countUserSessions($userId);

        self::assertSame(0, $count);
    }

    /**
     * ユーザーID内のバックスラッシュ「\」をエスケープすることを検証
     *
     * 「\」はRedisのエスケープ文字として機能するため、ユーザーID内に含まれる場合は
     * 二重エスケープが必要。このテストでは「\」が正しく「\\」にエスケープされ、
     * 他の特殊文字のエスケープと混同されないことを確認する。
     */
    public function testEscapesBackslashInUserId(): void
    {
        $userId = 'user\\test';
        $escapedPattern = 'user' . 'user\\\\test' . '_*';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with($escapedPattern)
            ->willReturn([]);

        $count = $this->helper->countUserSessions($userId);

        self::assertSame(0, $count);
    }

    /**
     * 複数のRedis特殊文字が混在する場合のエスケープを検証
     *
     * ユーザーIDに複数の特殊文字（*、?、[、]）が含まれる場合でも、
     * すべての文字が正しくエスケープされることを確認する。
     * これは複雑なパターンインジェクション攻撃に対する防御を検証する。
     */
    public function testEscapesMultipleSpecialCharactersInUserId(): void
    {
        $userId = 'user*?[test]';
        $escapedPattern = 'user' . 'user\\*\\?\\[test\\]' . '_*';

        $this->connection->expects(static::once())
            ->method('keys')
            ->with($escapedPattern)
            ->willReturn([]);

        $deletedCount = $this->helper->forceLogoutUser($userId);

        self::assertSame(0, $deletedCount);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetUserIdAndRegenerateSuccess(): void
    {
        // セッションを開始
        session_start();
        $oldSessionId = session_id();

        // セッションIDジェネレータを使用してログイン時のセッションID再生成をテスト
        $userId = 'test_user_123';
        $result = $this->helper->setUserIdAndRegenerate($userId);

        self::assertTrue($result);

        // セッションIDが変更されたことを確認
        $newSessionId = session_id();
        self::assertNotEquals($oldSessionId, $newSessionId);

        // ジェネレータにユーザーIDが設定されたことを確認
        self::assertSame($userId, $this->generator->getUserId());

        session_destroy();
    }

    /**
     * @runInSeparateProcess
     */
    public function testSetUserIdAndRegenerateWithInvalidUserId(): void
    {
        session_start();

        // UserSessionIdGeneratorは予約語をバリデーション
        $this->expectException(\InvalidArgumentException::class);

        // 空文字列は無効
        $this->helper->setUserIdAndRegenerate('');
    }

    /**
     * セッションが開始されていない状態でsetUserIdAndRegenerate()を呼び出すとLogicExceptionが投げられることを確認
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSetUserIdAndRegenerateWithoutActiveSession(): void
    {
        // セッションが開始されていないことを確認
        self::assertSame(PHP_SESSION_NONE, session_status());

        // LogicExceptionが投げられることを期待
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Session is not active. Call session_start() before this method.');

        // エラーログが出力されることを確認
        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'Session is not active. Call session_start() before this method.',
                [
                    'user_id' => 'test-user',
                    'session_status' => PHP_SESSION_NONE,
                ]
            );

        // setUserIdAndRegenerate()を呼び出すとLogicExceptionが投げられる
        $this->helper->setUserIdAndRegenerate('test-user');
    }
}
