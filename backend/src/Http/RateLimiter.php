<?php

declare(strict_types=1);

namespace App\Http;

use App\Database;

final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }
    public function allow(string $subject, int $limit, int $window): bool
    {
        $key = hash('sha256', $subject).':'.intdiv(time(), $window);
        $hits = (int)$this->db->connection->fetchOne('INSERT INTO rate_limits (bucket,hits,created_at) VALUES (?,1,?) ON CONFLICT (bucket) DO UPDATE SET hits=rate_limits.hits+1 RETURNING hits', [$key,time()]);
        $this->db->connection->executeStatement('DELETE FROM rate_limits WHERE created_at < ?', [time() - 86400]);
        return $hits <= $limit;
    }
}
