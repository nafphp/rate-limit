# naf/rate-limit (unreleased)

Optional atomic PDO fixed-window counters. `PdoLimiter(PDO)::install()` belongs in an explicit host migration. `consume(key, limit, windowSeconds)` returns `allowed`, `remaining`, and `retry_after`; the upsert and read execute within a short owned transaction. Hashes are stored rather than raw account/IP keys. A fixed window can admit a burst across its boundary; it is not a sliding window.

The host defines namespaced account/IP limits and the 429 response. Use the direct peer address unless a trusted proxy policy has been explicitly configured. Do not place consumption inside a domain transaction. MariaDB/PostgreSQL and SQLite are supported. Periodically call `cleanup()` for expired buckets. No request is automatically intercepted by installation.

## Framework guard integration

Once installed in a NAF application, this plugin registers `rateLimit` on the existing
`Naf\guard()` registry. The registry and its standard guards remain in `naf/framework`;
no separate guards package is required. The plugin works in HTTP and CLI applications.

The limiter is resolved lazily from the container on the first guard call. Bind the host's
configured connection under `PDO::class` before handling requests, unless another installed
plugin already supplies that binding. For example, with `naf/database`, add this to the
host's root `bootstrap.php` after loading the autoloader and before `app()->run()`:

```php
use function Naf\app;
use function Naf\Database\database;

app()->container()->set(PDO::class, static fn() =>
    database() ?? throw new LogicException('Rate limiting requires a configured database.'));
```

Run `(new \Naf\RateLimit\PdoLimiter($pdo))->install()` in an explicit migration using that
connection. Booting the plugin neither creates tables nor opens the connection or consumes
a limit. Alternatively, bind `Naf\RateLimit\PdoLimiter::class` to a limiter constructed with
the desired connection. Existing bindings are retained and host overrides remain possible.

In a handler, use a namespaced key derived from a trusted account identity or peer address:

```php
use function Naf\{guard, json};

// $accountId is the already verified identity's ID.
$decision = guard()->rateLimit('export:account:' . $accountId, 10, 60);
if (!$decision['allowed']) {
    return json(['error' => 'Too many requests'], 429)
        ->withHeader('Retry-After', (string) $decision['retry_after']);
}
// Continue with the export.
```

`guard()->run('rateLimit', $key, $limit, $windowSeconds)` is equivalent. Both return the
same array as `PdoLimiter::consume()`; check its `allowed` field, not the array's truthiness.
Direct injection and calls to `PdoLimiter` remain supported and share the same buckets.
Storage errors propagate; they do not silently allow the operation. The host chooses keys,
limits and responses. A guard call does not itself authenticate the caller.

## Verification

Run `composer test` and `composer validate --strict`. Tests require `pdo_sqlite` and use only
in-memory counters. `NAF_TEST_AUTOLOAD=/path/to/verified/host/vendor/autoload.php composer test`
can exercise the same contract against an installed integration host.

## PHP code style

Source, tests and PHP templates follow the shared [NAF code style](https://github.com/nafphp/docs/blob/main/CODE_STYLE.md)
(PER Coding Style 3.0 with the Nafinity readability rules). After `composer install`, run
`composer style:check` to verify formatting or `composer style:fix` to apply it. The formatter
is a development dependency. Review template output and run the package checks after changes.
