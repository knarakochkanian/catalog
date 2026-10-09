<?php

declare(strict_types=1);

namespace Tests;

use App\Database;
use App\Import\ExcelReader;
use App\Import\ImageDownloader;
use App\Repository\JobRepository;
use App\Repository\ProductRepository;
use App\Service\ImportProcessor;
use App\Service\ExportService;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class ImportFlowTest extends TestCase
{
    private string $dir;
    private Database $db;
    private ProductRepository $products;
    private JobRepository $jobs;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/import-test-'.bin2hex(random_bytes(8));
        mkdir($this->dir.'/uploads', 0700, true);
        mkdir($this->dir.'/images', 0700, true);
        $this->db = TestDatabase::fresh();
        $this->products = new ProductRepository($this->db);
        $this->jobs = new JobRepository($this->db);
    }
    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/uploads/*') ?: [] as $p) {
            unlink($p);
        }
        foreach (glob($this->dir.'/images/*') ?: [] as $p) {
            unlink($p);
        }
        foreach (glob($this->dir.'/*') ?: [] as $p) {
            if (is_file($p)) {
                unlink($p);
            }
        }
        rmdir($this->dir.'/uploads');
        rmdir($this->dir.'/images');
        rmdir($this->dir);
    }
    private function process(string $file, bool $failImages = false): string
    {
        $name = bin2hex(random_bytes(16)).'.xlsx';
        copy($file, $this->dir.'/uploads/'.$name);
        $job = $this->jobs->create($name, 1);
        $downloader = new class ($this->dir, $failImages) implements ImageDownloader {
            public function __construct(private string $dir, private bool $fail)
            {
            }
            public function download(string $url): array
            {
                if ($this->fail) {
                    throw new \InvalidArgumentException('Изображение недоступно.');
                }
                $path = bin2hex(random_bytes(16)).'.png';
                file_put_contents($this->dir.'/images/'.$path, 'image-test');
                return ['url' => $url,'path' => $path];
            }
            public function remove(string $path): void
            {
                if (is_file($this->dir.'/images/'.$path)) {
                    unlink($this->dir.'/images/'.$path);
                }
            }
        };
        $processor = new ImportProcessor($this->db, $this->jobs, $this->products, new ExcelReader(), $downloader, $this->dir);
        $processor->process($job);
        $processor->process($job); // Queue redelivery is a no-op.
        return $job;
    }
    public function testSampleImportRepeatAndExportReimport(): void
    {
        $first = $this->jobs->get($this->process(__DIR__.'/fixtures/products.xlsx'));
        self::assertSame('completed', $first['status']);
        self::assertSame(40, $first['created']);
        self::assertSame(0, $first['skipped']);
        self::assertSame(131, (int)$this->db->connection->fetchOne('SELECT COUNT(*) FROM product_images'));
        $snapshot = $this->products->get(1);
        self::assertSame('1320.00', $snapshot['price']);
        self::assertSame('33.3333', $snapshot['discount']);
        $repeat = $this->jobs->get($this->process(__DIR__.'/fixtures/products.xlsx'));
        self::assertSame(0, $repeat['created']);
        self::assertSame(40, $repeat['updated']);
        self::assertSame(40, $this->products->list(1, 20, '', null, null)['total']);
        $file = (new ExportService($this->products, $this->dir))->export();
        $reimport = $this->jobs->get($this->process($file));
        unlink($file);
        self::assertSame(40, $reimport['updated']);
        self::assertSame(0, $reimport['skipped']);
        $result = $this->products->get(1);
        foreach (['external_code','name','description','price','purchase_price','discount'] as $key) {
            self::assertSame($snapshot[$key], $result[$key]);
        }
        $before = array_column($snapshot['attributes'], 'value', 'key');
        $after = array_column($result['attributes'], 'value', 'key');
        ksort($before);
        ksort($after);
        self::assertSame($before, $after);
        self::assertSame(array_column($snapshot['images'], 'url'), array_column($result['images'], 'url'));
    }
    public function testImageFailureLeavesExistingProductAndRelationsUntouched(): void
    {
        $this->process(__DIR__.'/fixtures/products.xlsx');
        $before = $this->products->get(1);
        $job = $this->jobs->get($this->process(__DIR__.'/fixtures/products.xlsx', true));
        self::assertSame(40, $job['skipped']);
        self::assertCount(40, $job['errors']);
        self::assertSame(2, $job['errors'][0]['row']);
        self::assertSame($before, $this->products->get(1));
    }
    public function testCorruptWorkbookProducesFailedJob(): void
    {
        $file = $this->dir.'/broken.xlsx';
        file_put_contents($file, 'not an xlsx');
        $job = $this->jobs->get($this->process($file));
        self::assertSame('failed', $job['status']);
        self::assertNotEmpty($job['message']);
        self::assertSame(0, $this->products->list(1, 20, '', null, null)['total']);
    }

    public function testDuplicateCodeReplacementDoesNotReuseDeletedImage(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray([
            ['Внешний код','Наименование','Цена: Цена продажи','Закупочная цена','Доп. поле: Ссылки на фото'],
            ['A','First','100','50','https://example.com/x.png'],
            ['A','Updated','100','50','https://example.com/y.png'],
            ['B','Second','100','50','https://example.com/x.png'],
        ], null, 'A1', true);
        $file = $this->dir.'/duplicates.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);
        $job = $this->jobs->get($this->process($file));
        self::assertSame(2, $job['created']);
        self::assertSame(1, $job['updated']);
        self::assertSame(0, $job['skipped']);
        foreach ($this->db->connection->fetchFirstColumn('SELECT path FROM product_images') as $path) {
            self::assertFileExists($this->dir.'/images/'.$path);
        }
    }
}
