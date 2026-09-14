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
            'CREATE TABLE IF NOT EXISTS naf_rate_limits (bucket VARCHAR(64) PRIMARY KEY,window_start BIGINT NOT NULL,hits BIGINT NOT NULL,expires_at BIGINT NOT NULL)',
        );
    }

    /** The trusted host supplies a namespaced key. Returns allowed, remaining and retry_after. */
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
            $sql = 'INSERT INTO naf_rate_limits(bucket,window_start,hits,expires_at) VALUES(?,?,1,?)';
            $sql
                .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                    ? ' ON DUPLICATE KEY UPDATE hits=IF(window_start=VALUES(window_start),hits+1,1),window_start=VALUES(window_start),expires_at=VALUES(expires_at)'
                    : ' ON CONFLICT(bucket) DO UPDATE SET hits=CASE WHEN naf_rate_limits.window_start=excluded.window_start THEN naf_rate_limits.hits+1 ELSE 1 END,window_start=excluded.window_start,expires_at=excluded.expires_at';
            $this->pdo->prepare($sql)->execute([$bucket, $window, $expires]);
            $q = $this->pdo->prepare('SELECT hits FROM naf_rate_limits WHERE bucket=?');
            $q->execute([$bucket]);
            $hits = (int) $q->fetchColumn();
            $this->pdo->commit();

            return [
                'allowed'     => $hits <= $limit,
                'remaining'   => max(0, $limit - $hits),
                'retry_after' => max(1, $expires - $now),
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function cleanup(): int
    {
        $q = $this->pdo->prepare('DELETE FROM naf_rate_limits WHERE expires_at<?');
        $q->execute([time() - 86400]);

        return $q->rowCount();
    }
}
