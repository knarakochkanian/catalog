<?php

declare(strict_types=1);

namespace Tests;

use App\Database;
use App\Import\ProductData;
use App\Repository\ProductRepository;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    private Database $db;
    private ProductRepository $products;

    protected function setUp(): void
    {
        $this->db = TestDatabase::fresh();
        $this->products = new ProductRepository($this->db);
    }

    public function testUpsertReplacesChildrenAndPreservesIdentity(): void
    {
        $data = new ProductData('00123', 'Товар', 'Описание', '1320.00', '880.00', '33.3333', ['Размер' => 'M','Ноль' => '0'], []);
        self::assertTrue($this->products->upsert($data, [['url' => 'https://example.com/a.png','path' => 'test.png']]));
        $id = $this->products->list(1, 20, '', null, null)['items'][0]['id'];
        $new = new ProductData('00123', 'Обновлено', '', '1200.00', '880.00', '26.6666', ['Ноль' => '0'], []);
        self::assertFalse($this->products->upsert($new, []));
        $result = $this->products->get($id);
        self::assertNotNull($result);
        self::assertSame('00123', $result['external_code']);
        self::assertSame('Обновлено', $result['name']);
        self::assertCount(1, $result['attributes']);
        self::assertSame('0', $result['attributes'][0]['value']);
        self::assertCount(0, $result['images']);
        self::assertSame(1, $this->products->list(1, 20, '', null, null)['total']);
        self::assertTrue($this->products->delete($id));
        self::assertNull($this->products->get($id));
        self::assertSame(0, (int) $this->db->connection->fetchOne('SELECT COUNT(*) FROM product_attributes'));
    }

    public function testServerPaginationAndPriceFilters(): void
    {
        for ($i = 1;$i <= 5;$i++) {
            $this->products->upsert(new ProductData((string)$i, 'Product '.$i, '', (string)($i * 100).'.00', '10.00', '0.0000', [], []), []);
        }
        $page = $this->products->list(2, 2, 'Product', '100.00', '400.00');
        self::assertSame(4, $page['total']);
        self::assertSame(2, $page['total_pages']);
        self::assertCount(2, $page['items']);
        self::assertSame('3', $page['items'][0]['external_code']);
    }

    public function testSearchMatchesEveryWordRegardlessOfCaseOrderAndWhitespace(): void
    {
        $names = [
            'Бермуды мужские, Grigio/Verde, OMSA, 46(M), РОССИЯ',
            'Бермуды мужские, Grigio, OMSA, 46(M), РОССИЯ',
            'Бермуды женские, MINIMI',
        ];
        foreach ($names as $index => $name) {
            $this->products->upsert(new ProductData('search-'.$index, $name, '', $index === 0 ? '1320.00' : '960.00', '640.00', '0.0000', [], []), []);
        }
        foreach (['бермуды omsa','БЕРМУДЫ oMsA','omsa бермуды',"  бермуды\t\u{00A0} OMSA  ",'omsa omsa бермуды'] as $query) {
            self::assertSame(2, $this->products->list(1, 20, $query, null, null)['total'], $query);
        }
        self::assertSame(0, $this->products->list(1, 20, 'бермуды missing', null, null)['total']);
        self::assertSame(3, $this->products->list(1, 20, " \t\u{00A0} ", null, null)['total']);
        $filtered = $this->products->list(1, 20, 'OMSA бермуды', '1300.00', '1400.00');
        self::assertSame(1, $filtered['total']);
        self::assertSame('search-0', $filtered['items'][0]['external_code']);
        $page = $this->products->list(2, 1, 'бермуды omsa', null, null);
        self::assertSame(2, $page['total']);
        self::assertSame(2, $page['total_pages']);
        self::assertSame('search-1', $page['items'][0]['external_code']);
    }

    public function testSearchTreatsLikeWildcardCharactersAsLiteralText(): void
    {
        $this->products->upsert(new ProductData('literal', 'Бермуды 100% Хлопок _Special!', '', '100.00', '50.00', '50.0000', [], []), []);
        $this->products->upsert(new ProductData('other', 'Бермуды 1000 Хлопок xSpecial', '', '100.00', '50.00', '50.0000', [], []), []);
        foreach (['100%','_Special!','% _'] as $query) {
            $result = $this->products->list(1, 20, $query, null, null);
            self::assertSame(1, $result['total']);
            self::assertSame('literal', $result['items'][0]['external_code']);
        }
    }

    public function testAttributeLimitKeepsExportWithinImportContract(): void
    {
        $attributes = [];
        for ($i = 0;$i < 194;$i++) {
            $attributes['key'.$i] = 'value';
        }
        $this->products->upsert(new ProductData('bound', 'Bounded', '', '100.00', '50.00', '50.0000', $attributes, []), []);
        $dir = sys_get_temp_dir().'/export-limit-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $file = (new \App\Service\ExportService($this->products, $dir))->export();
        try {
            $rows = (new \App\Import\ExcelReader())->read($file);
            self::assertNull($rows[0]['error']);
            self::assertCount(194, $rows[0]['data']->attributes);
        } finally {
            unlink($file);
            rmdir($dir);
        }
        $attributes['one_more'] = 'value';
        try {
            $this->products->upsert(new ProductData('other', 'Too wide', '', '100.00', '50.00', '50.0000', $attributes, []), []);
            self::fail('Catalog accepted more fields than its reimportable export supports.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('194', $e->getMessage());
            self::assertSame(1, $this->products->list(1, 20, '', null, null)['total']);
        }
    }

    public function testTextBudgetRejectsRowBeforeReplacingExistingData(): void
    {
        $attributes = [];
        for ($i = 0;$i < 194;$i++) {
            $attributes['key'.$i] = str_repeat('x', 10000);
        }
        $this->products->upsert(new ProductData('one', 'First', '', '100.00', '50.00', '50.0000', $attributes, []), []);
        try {
            $this->products->upsert(new ProductData('two', 'Second', '', '100.00', '50.00', '50.0000', $attributes, []), []);
            self::fail('Accepted text that cannot fit safe export bounds.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('2 MiB', $e->getMessage());
            self::assertSame(1, $this->products->list(1, 20, '', null, null)['total']);
        }
    }
}
