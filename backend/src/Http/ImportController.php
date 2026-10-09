<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\JobRepository;
use App\Queue\QueuePublisher;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

final class ImportController
{
    public function __construct(private JobRepository $jobs, private QueuePublisher $publisher, private RateLimiter $limits, private string $storage)
    {
    }
    public function upload(Request $request, Response $response): Response
    {
        $owner = (int)$request->getAttribute('user_id');
        if (!$this->limits->allow('import:'.$owner, 5, 60)) {
            throw new ApiException(429, 'rate_limit', 'Не более 5 импортов в минуту. Повторите позже.');
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ApiException(422, 'invalid_file', 'Не удалось загрузить файл. Максимальный размер — 10 MiB.');
        }
        $name = bin2hex(random_bytes(16)).'.xlsx';
        $path = $this->storage.'/uploads/'.$name;
        try {
            $file->moveTo($path);
            (new UploadValidator())->validate($path, $file->getClientFilename() ?? '');
            $id = $this->jobs->create($name, $owner);
        } catch (\Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            } throw $e;
        }
        try {
            $this->publisher->publish($id);
        } catch (\Throwable $e) {
            error_log('Import remains in durable outbox: '.$e->getMessage());
        }
        return Json::send($response, ['id' => $id,'status' => 'queued'], 202);
    }
    /** @param array<string,string> $args */
    public function status(Request $request, Response $response, array $args): Response
    {
        $job = $this->jobs->get($args['id'], (int)$request->getAttribute('user_id'));
        if (!$job) {
            throw new ApiException(404, 'not_found', 'Задание импорта не найдено.');
        }
        unset($job['filename'],$job['owner_id'],$job['created_at'],$job['published_at']);
        return Json::send($response, $job);
    }
}
