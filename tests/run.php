<?php

declare(strict_types=1);

use Naf\RateLimit\PdoLimiter;

use function Naf\app;
use function Naf\guard;

define('BASE_PATH', __DIR__ . '/Fixtures');
require getenv('NAF_TEST_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
function check(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}


$pdo     = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$limiter = new PdoLimiter($pdo);
$limiter->install();
check($limiter->consume('a', 2, 60, 120)['allowed'], 'first');
check($limiter->consume('a', 2, 60, 121)['allowed'], 'second');
check(!$limiter->consume('a', 2, 60, 122)['allowed'], 'limit');
check($limiter->consume('b', 2, 60, 122)['allowed'], 'independent');
check($limiter->consume('a', 2, 60, 180)['allowed'], 'expiry');
$pdo->beginTransaction();

try {
    $limiter->consume('a', 1, 60);
    throw new RuntimeException('Unexpected nested transaction');
} catch (LogicException) {
} finally {
    $pdo->rollBack();
}

$container = app()->container();
$container->set(PDO::class, static function (): never {
    throw new RuntimeException('The guard must not resolve PDO during plugin boot.');
});
guard()->register('hostCheck', static fn() => 'retained');
require dirname(__DIR__) . '/bootstrap.php';
check(guard()->has('rateLimit'), 'plugin guard registered');
check(guard()->hostCheck() === 'retained', 'existing guard retained');

// The host can bind its connection after plugin boot and migrate explicitly.
$container->set(PDO::class, $pdo);
check($pdo->query("SELECT COUNT(*) FROM naf_rate_limits")->fetchColumn() === 2, 'boot did not consume');
check(guard()->rateLimit('guard', 2, 60, 120) === [
    'allowed' => true, 'remaining' => 1, 'retry_after' => 60,
], 'guard returns the existing limiter result');
check(guard()->run('rateLimit', 'guard', 2, 60, 121)['allowed'], 'registry run consumes');
$blocked = guard()->rateLimit('guard', 2, 60, 122);
check(!$blocked['allowed'] && $blocked['remaining'] === 0 && $blocked['retry_after'] === 58, 'guard denies exhausted bucket');
check(guard()->rateLimit('guard', 2, 60, 180)['allowed'], 'guard window expires');

// A host override must also take effect after the default limiter was resolved.
$otherPdo     = new PDO('sqlite::memory:');
$otherLimiter = new PdoLimiter($otherPdo);
$otherLimiter->install();
$container->set(PdoLimiter::class, $otherLimiter);
check(guard()->rateLimit('override', 1, 60, 120)['allowed'], 'late limiter override');
check((int) $otherPdo->query('SELECT COUNT(*) FROM naf_rate_limits')->fetchColumn() === 1, 'override connection used');

// A binding supplied by an earlier plugin must survive this bootstrap too.
require dirname(__DIR__) . '/bootstrap.php';
check($container->get(PdoLimiter::class) === $otherLimiter, 'existing limiter binding retained');
$otherPdo->beginTransaction();

try {
    guard()->rateLimit('nested', 1, 60, 120);
    throw new RuntimeException('Guard swallowed transaction protection');
} catch (LogicException) {
    check($otherPdo->inTransaction(), 'caller transaction retained');
} finally {
    $otherPdo->rollBack();
}
echo "Rate-limit contract cases passed.\n";
