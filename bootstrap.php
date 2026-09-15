<?php

declare(strict_types=1);

use Naf\RateLimit\PdoLimiter;

use function Naf\app;
use function Naf\guard;

$container = app()->container();

if (!$container->has(PdoLimiter::class)) {
    $container->set(
        PdoLimiter::class,
        static fn() => new PdoLimiter($container->get(PDO::class)),
    );
}

guard()->register(
    'rateLimit',
    static fn(string $key, int $limit, int $windowSeconds, ?int $now = null): array
        => $container->get(PdoLimiter::class)->consume($key, $limit, $windowSeconds, $now),
);
