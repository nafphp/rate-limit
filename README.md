# naf/rate-limit (unreleased)

Optional atomic PDO fixed-window counters. `PdoLimiter(PDO)::install()` belongs in an explicit host migration. `consume(key, limit, windowSeconds)` returns `allowed`, `remaining`, and `retry_after`; the upsert and read execute within a short owned transaction. Hashes are stored rather than raw account/IP keys. A fixed window can admit a burst across its boundary; it is not a sliding window.

The host defines namespaced account/IP limits and the 429 response. Use the direct peer address unless a trusted proxy policy has been explicitly configured. Do not place consumption inside a domain transaction. MariaDB/PostgreSQL and SQLite are supported. Periodically call `cleanup()` for expired buckets. No request is automatically intercepted by installation.
