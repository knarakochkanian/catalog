<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database;

final class JobRepository
{
    public function __construct(private Database $db)
    {
    }

    public function create(string $filename, int $ownerId): string
    {
        $id = bin2hex(random_bytes(16));
        $this->db->connection->insert('import_jobs', ['id' => $id,'owner_id' => $ownerId,'filename' => $filename,'status' => 'queued','total' => 0,'processed' => 0,'created' => 0,'updated' => 0,'skipped' => 0,'errors' => '[]','created_at' => time()]);
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function get(string $id, ?int $ownerId = null): ?array
    {
        $row = $this->db->connection->fetchAssociative('SELECT * FROM import_jobs WHERE id=?'.($ownerId !== null ? ' AND owner_id=?' : ''), $ownerId !== null ? [$id,$ownerId] : [$id]);
        if (!$row) {
            return null;
        }
        foreach (['total','processed','created','updated','skipped','owner_id'] as $key) {
            $row[$key] = (int)$row[$key];
        }
        $row['errors'] = json_decode((string)$row['errors'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    /** @param array<string,mixed> $changes */
    public function update(string $id, array $changes): void
    {
        if (isset($changes['errors'])) {
            $changes['errors'] = json_encode($changes['errors'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $this->db->connection->update('import_jobs', $changes, ['id' => $id]);
    }

    /**
     * Durable outbox; stale publication can be reconciled after broker loss.
     * @return list<string>
     */
    public function pending(): array
    {
        /** @var list<string> $result */
        $result = $this->db->connection->fetchFirstColumn("SELECT id FROM import_jobs WHERE status IN ('queued','processing') AND (published_at IS NULL OR published_at < ?) ORDER BY created_at LIMIT 20", [time() - 300]);
        return $result;
    }
}
