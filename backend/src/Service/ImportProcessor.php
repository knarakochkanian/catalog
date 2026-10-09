<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use App\Import\ExcelReader;
use App\Import\ImageDownloader;
use App\Repository\JobRepository;
use App\Repository\ProductRepository;

final class ImportProcessor
{
    public function __construct(private Database $db, private JobRepository $jobs, private ProductRepository $products, private ExcelReader $reader, private ImageDownloader $images, private string $storage)
    {
    }

    public function process(string $id): void
    {
        $lock = fopen($this->storage.'/import.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Не удалось заблокировать импорт.');
        }
        try {
            $job = $this->jobs->get($id);
            if (!$job || in_array($job['status'], ['completed','failed'], true)) {
                return;
            }
            $path = $this->storage.'/uploads/'.basename((string)$job['filename']);
            try {
                $rows = $this->reader->read($path);
            } catch (\InvalidArgumentException $e) {
                $this->jobs->update($id, ['status' => 'failed','message' => $e->getMessage()]);
                if (is_file($path)) {
                    unlink($path);
                } return;
            }
            $this->jobs->update($id, ['status' => 'processing','total' => count($rows)]);
            /** @var list<array{sheet:string,row:int,message:string}> $errors */
            $errors = $job['errors'];
            $processed = (int)$job['processed'];
            $created = (int)$job['created'];
            $updated = (int)$job['updated'];
            $skipped = (int)$job['skipped'];
            $cache = [];
            foreach ($rows as $index => $row) {
                if ($index < $processed) {
                    continue;
                }
                $downloaded = [];
                $newDownloads = [];
                $committed = false;
                $oldPaths = [];
                try {
                    if ($row['error'] !== null || $row['data'] === null) {
                        throw new \InvalidArgumentException($row['error'] ?? 'Пустая строка.');
                    }
                    $data = $row['data'];
                    foreach ($data->imageUrls as $url) {
                        if (isset($cache[$url])) {
                            $downloaded[] = $cache[$url];
                        } else {
                            $image = $this->images->download($url);
                            $downloaded[] = $image;
                            $newDownloads[] = $image;
                        }
                    }
                    $oldPaths = $this->products->imagePathsForCode($data->externalCode);
                    $this->db->connection->beginTransaction();
                    $isCreated = $this->products->upsert($data, $downloaded);
                    $this->jobs->update($id, ['processed' => $index + 1,'created' => $created + ($isCreated ? 1 : 0),'updated' => $updated + ($isCreated ? 0 : 1),'skipped' => $skipped,'errors' => $errors]);
                    $this->db->connection->commit();
                    $committed = true;
                    foreach ($newDownloads as $image) {
                        $cache[$image['url']] = $image;
                    }
                    $isCreated ? ++$created : ++$updated;
                } catch (\Throwable $e) {
                    if ($this->db->connection->isTransactionActive()) {
                        $this->db->connection->rollBack();
                    }
                    foreach ($newDownloads as $image) {
                        $this->images->remove($image['path']);
                    }
                    // Validation and downloads are row errors; DB failures must retry without silently dropping a row.
                    if (!$e instanceof \InvalidArgumentException) {
                        throw $e;
                    }
                    ++$skipped;
                    $errors[] = ['sheet' => $row['sheet'],'row' => $row['row'],'message' => $e->getMessage()];
                    $this->jobs->update($id, ['processed' => $index + 1,'created' => $created,'updated' => $updated,'skipped' => $skipped,'errors' => $errors]);
                }
                if ($committed) {
                    foreach ($oldPaths as $old) {
                        if (!$this->products->imageExists($old)) {
                            foreach ($cache as $url => $cached) {
                                if ($cached['path'] === $old) {
                                    unset($cache[$url]);
                                }
                            }
                            try {
                                $this->images->remove($old);
                            } catch (\Throwable $e) {
                                error_log('Image cleanup after commit failed: '.$e->getMessage());
                            }
                        }
                    }
                }
            }
            $this->jobs->update($id, ['status' => 'completed']);
            if (is_file($path)) {
                unlink($path);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
