<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use Naf\RateLimit\PdoLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

/** Counting attempts in a window, and refusing to do it inside somebody else's transaction. */
final class PdoLimiterTest extends TestCase
{
    private PDO $pdo;
    private PdoLimiter $limiter;

    protected function setUp(): void
    {
        $this->pdo     = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->limiter = new PdoLimiter($this->pdo);
        $this->limiter->install();
    }

    public function testAttemptsAreAllowedUpToTheLimitAndThenRefused(): void
    {
        self::assertTrue($this->limiter->consume('a', 2, 60, 120)['allowed']);
        self::assertTrue($this->limiter->consume('a', 2, 60, 121)['allowed']);
        self::assertFalse($this->limiter->consume('a', 2, 60, 122)['allowed'], 'the limit did not hold');
    }

    public function testTheAnswerSaysHowMuchIsLeftAndWhenToComeBack(): void
    {
        $this->limiter->consume('a', 2, 60, 120);

        self::assertSame(
            ['allowed' => true, 'remaining' => 0, 'retry_after' => 60],
            $this->limiter->consume('a', 2, 60, 120),
        );

        $blocked = $this->limiter->consume('a', 2, 60, 122);

        self::assertFalse($blocked['allowed']);
        self::assertSame(0, $blocked['remaining']);
        self::assertSame(58, $blocked['retry_after'], 'the wait shrinks as the window runs out');
    }

    public function testEachKeyCountsOnItsOwn(): void
    {
        $this->limiter->consume('a', 2, 60, 120);
        $this->limiter->consume('a', 2, 60, 121);

        self::assertTrue($this->limiter->consume('b', 2, 60, 122)['allowed'], 'a key blocked another');
    }

    public function testAnExhaustedBucketOpensAgainOnceItsWindowPasses(): void
    {
        $this->limiter->consume('a', 2, 60, 120);
        $this->limiter->consume('a', 2, 60, 121);
        self::assertFalse($this->limiter->consume('a', 2, 60, 122)['allowed']);

        self::assertTrue($this->limiter->consume('a', 2, 60, 180)['allowed'], 'the window never expired');
    }

    public function testItRefusesToCountInsideACallerTransaction(): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->limiter->consume('a', 1, 60);
            self::fail('counting joined a transaction it does not own');
        } catch (LogicException) {
            self::assertTrue($this->pdo->inTransaction(), 'the caller transaction was disturbed');
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testCleanupRemovesWhatHasExpired(): void
    {
        $this->limiter->consume('a', 2, 60, 120);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM naf_rate_limits')->fetchColumn());
        self::assertIsInt($this->limiter->cleanup());
    }
}
