<?php

declare(strict_types=1);

use Naf\RateLimit\PdoLimiter;

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
echo "Rate-limit contract cases passed.\n";
