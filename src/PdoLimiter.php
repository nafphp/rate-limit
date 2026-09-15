<?php

declare(strict_types=1);

namespace Naf\RateLimit;

use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

final class PdoLimiter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function install(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS naf_rate_limits (
                bucket VARCHAR(64) PRIMARY KEY,
                window_start BIGINT NOT NULL,
                hits BIGINT NOT NULL,
                expires_at BIGINT NOT NULL
            )
            SQL,
        );
    }

    /**
     * The trusted host supplies a namespaced key.
     *
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function consume(string $key, int $limit, int $windowSeconds, ?int $now = null): array
    {
        if ($limit < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('Positive rate limit and window required.');
        }

        if ($this->pdo->inTransaction()) {
            throw new LogicException('Consume rate limits outside business transactions.');
        }

        $now ??= time();
        $window  = intdiv($now, $windowSeconds) * $windowSeconds;
        $expires = $window + $windowSeconds;
        $bucket  = hash('sha256', $key . ':' . $windowSeconds);

        $this->pdo->beginTransaction();

        try {
            $sql = 'INSERT INTO naf_rate_limits (bucket, window_start, hits, expires_at) VALUES (?, ?, 1, ?)';
            $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? <<<'SQL'

                ON DUPLICATE KEY UPDATE
                    hits = IF(window_start = VALUES(window_start), hits + 1, 1),
                    window_start = VALUES(window_start),
                    expires_at = VALUES(expires_at)
                SQL
                : <<<'SQL'

                ON CONFLICT(bucket) DO UPDATE SET
                    hits = CASE
                        WHEN naf_rate_limits.window_start = excluded.window_start THEN naf_rate_limits.hits + 1
                        ELSE 1
                    END,
                    window_start = excluded.window_start,
                    expires_at = excluded.expires_at
                SQL;
            $this->pdo->prepare($sql)->execute([$bucket, $window, $expires]);

            $statement = $this->pdo->prepare('SELECT hits FROM naf_rate_limits WHERE bucket = ?');
            $statement->execute([$bucket]);
            $hits = (int) $statement->fetchColumn();

            $this->pdo->commit();

            return [
                'allowed'     => $hits <= $limit,
                'remaining'   => max(0, $limit - $hits),
                'retry_after' => max(1, $expires - $now),
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cleanup(): int
    {
        $statement = $this->pdo->prepare('DELETE FROM naf_rate_limits WHERE expires_at < ?');
        $statement->execute([time() - 86400]);

        return $statement->rowCount();
    }
}
