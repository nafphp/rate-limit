# NAF Rate Limit

> **Count attempts, and refuse the ones over the line.**

Atomic fixed-window counters in your database, registered as a `rateLimit` guard on NAF's
existing `Naf\guard()` registry. Installing it intercepts nothing: the host chooses the keys,
the limits and what a refusal looks like.

```php
$decision = guard()->rateLimit('export:account:' . $accountId, 10, 60);

if (!$decision['allowed']) {
    return json(['error' => 'Too many requests'], 429)
        ->withHeader('Retry-After', (string) $decision['retry_after']);
}
```

> 🧩 Part of the official NAF plugin collection.
> Install it when something needs a limit, and nothing else.

## Documentation

**[Read the documentation →](https://nafphp.github.io/docs/)**

What this package does, how the limiter is wired to a connection and what a fixed window can
and cannot promise lives in the [NAF documentation](https://nafphp.github.io/docs/). Not sure
which packages you need? [Start here](https://nafphp.github.io/docs/choosing-packages/).

## Install

```bash
composer require naf/rate-limit
```

Needs `ext-pdo` and a database the host binds under `PDO::class`.

## License

MIT. Part of [NAF](https://github.com/nafphp/framework).
