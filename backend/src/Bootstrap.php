<?php

declare(strict_types=1);

namespace App;

use App\Import\ExcelReader;
use App\Import\SafeImageDownloader;
use App\Repository\JobRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Service\ImportProcessor;
use App\Service\ExportService;
use App\Queue\QueuePublisher;
use Symfony\Component\Dotenv\Dotenv;

final class Bootstrap
{
    public readonly Database $db;
    public readonly ProductRepository $products;
    public readonly JobRepository $jobs;
    public readonly UserRepository $users;
    public readonly ImportProcessor $processor;
    public readonly ExportService $export;
    public readonly QueuePublisher $publisher;
    public readonly string $storage;
    public function __construct()
    {
        $env = dirname(__DIR__, 2).'/.env';
        if (is_file($env)) {
            self::loadEnvironment($env);
        }
        $this->storage = self::env('STORAGE_PATH', dirname(__DIR__).'/var/storage');
        foreach (['','/uploads','/images','/sessions'] as $sub) {
            if (!is_dir($this->storage.$sub) && !mkdir($this->storage.$sub, 0700, true)) {
                throw new \RuntimeException('Не удалось создать хранилище.');
            }
        }
        $this->db = new Database(self::env('DATABASE_URL', 'postgresql://app:app@db:5432/products'));
        $this->products = new ProductRepository($this->db);
        $this->jobs = new JobRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->processor = new ImportProcessor($this->db, $this->jobs, $this->products, new ExcelReader(), new SafeImageDownloader($this->storage), $this->storage);
        $this->export = new ExportService($this->products, $this->storage);
        $this->publisher = new QueuePublisher($this->jobs, self::env('MESSENGER_TRANSPORT_DSN', 'amqp://app:app@rabbitmq:5672/%2f'));
    }
    public static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function loadEnvironment(string $path): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Не удалось прочитать окружение.');
        }
        foreach ((new Dotenv())->parse($contents, $path) as $name => $value) {
            // FPM and the development server may leave $_ENV empty; respect the process environment directly.
            if (getenv($name) === false) {
                putenv($name.'='.$value);
            }
        }
    }
}
