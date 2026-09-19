<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use Naf\RateLimit\PdoLimiter;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Naf\app;
use function Naf\guard;

/**
 * What booting the plugin does to the application.
 *
 * Each case runs in its own process: the bootstrap writes into the application
 * container and the guard registry, and a test that inherited another test's
 * container would be asserting on leftovers.
 */
final class GuardRegistrationTest extends TestCase
{
    private static function boot(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', __DIR__ . '/../Fixtures');
        }

        require dirname(__DIR__, 2) . '/bootstrap.php';
    }

    private static function limiter(PDO $pdo): PdoLimiter
    {
        $limiter = new PdoLimiter($pdo);
        $limiter->install();

        return $limiter;
    }

    #[RunInSeparateProcess]
    public function testBootingRegistersTheGuardWithoutOpeningAConnection(): void
    {
        app()->container()->set(PDO::class, static function (): never {
            throw new RuntimeException('the guard resolved PDO during plugin boot');
        });

        self::boot();

        self::assertTrue(guard()->has('rateLimit'));
    }

    #[RunInSeparateProcess]
    public function testBootingLeavesGuardsSomebodyElseRegistered(): void
    {
        guard()->register('hostCheck', static fn() => 'retained');

        self::boot();

        self::assertSame('retained', guard()->hostCheck(), 'an existing guard was replaced');
    }

    #[RunInSeparateProcess]
    public function testTheGuardCountsThroughTheConnectionBoundAfterBoot(): void
    {
        self::boot();

        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        app()->container()->set(PdoLimiter::class, self::limiter($pdo));

        self::assertSame(
            ['allowed' => true, 'remaining' => 1, 'retry_after' => 60],
            guard()->rateLimit('guard', 2, 60, 120),
        );
        self::assertTrue(guard()->run('rateLimit', 'guard', 2, 60, 121)['allowed'], 'the registry call differs');

        $blocked = guard()->rateLimit('guard', 2, 60, 122);

        self::assertFalse($blocked['allowed']);
        self::assertSame(0, $blocked['remaining']);
        self::assertTrue(guard()->rateLimit('guard', 2, 60, 180)['allowed'], 'the window never expired');
    }

    #[RunInSeparateProcess]
    public function testALimiterBoundAfterTheFirstCallStillTakesOver(): void
    {
        self::boot();

        $first = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        app()->container()->set(PdoLimiter::class, self::limiter($first));
        guard()->rateLimit('early', 5, 60, 120);

        $second = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        app()->container()->set(PdoLimiter::class, self::limiter($second));

        self::assertTrue(guard()->rateLimit('override', 1, 60, 120)['allowed']);
        self::assertSame(
            1,
            (int) $second->query('SELECT COUNT(*) FROM naf_rate_limits')->fetchColumn(),
            'the replacement connection was not the one used',
        );
    }

    #[RunInSeparateProcess]
    public function testBootingTwiceKeepsTheLimiterSomebodyElseBound(): void
    {
        self::boot();

        $pdo     = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $limiter = self::limiter($pdo);
        app()->container()->set(PdoLimiter::class, $limiter);

        self::boot();

        self::assertSame($limiter, app()->container()->get(PdoLimiter::class), 'a second boot replaced it');
    }

    #[RunInSeparateProcess]
    public function testTheGuardRefusesToCountInsideACallerTransaction(): void
    {
        self::boot();

        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        app()->container()->set(PdoLimiter::class, self::limiter($pdo));
        $pdo->beginTransaction();

        try {
            guard()->rateLimit('nested', 1, 60, 120);
            self::fail('the guard swallowed the transaction protection');
        } catch (LogicException) {
            self::assertTrue($pdo->inTransaction(), 'the caller transaction was disturbed');
        } finally {
            $pdo->rollBack();
        }
    }
}
