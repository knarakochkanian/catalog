<?php

declare(strict_types=1);

namespace Tests;

use App\Import\ExcelReader;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ZipArchive;

final class ImportExcelReaderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testSuppliedWorkbookHasFortyProductsAndOriginalPhotoAttributes(): void
    {
        $rows = (new ExcelReader())->read(__DIR__ . '/fixtures/products.xlsx');
        self::assertCount(40, $rows);
        self::assertSame('Лист1', $rows[0]['sheet']);
        self::assertSame(2, $rows[0]['row']);
        foreach ($rows as $row) {
            self::assertNull($row['error']);
            self::assertNotNull($row['data']);
            self::assertGreaterThan(0, (float) $row['data']->price);
            self::assertNotEmpty($row['data']->imageUrls);
            self::assertArrayHasKey('Ссылка на упаковку', $row['data']->attributes);
        }
    }

    public function testMapsHeadersPreservesZerosAndWhitespaceAndCalculatesDecimalDiscount(): void
    {
        $book = $this->workbook();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValueExplicit('A2', '000123', DataType::TYPE_STRING);
        $sheet->setCellValue('B2', 'Product');
        $sheet->setCellValueExplicit('C2', '220,00', DataType::TYPE_STRING);
        $sheet->setCellValue('D2', 176);
        $sheet->setCellValue('E2', "  description\n");
        $sheet->setCellValue('F2', 0);
        $sheet->setCellValue('G2', '');
        $sheet->setCellValue('H2', '  exact value  ');
        $rows = (new ExcelReader())->read($this->save($book));
        $product = $rows[0]['data'];
        self::assertNotNull($product);
        self::assertSame('000123', $product->externalCode);
        self::assertSame('220.00', $product->price);
        self::assertSame('176.00', $product->purchasePrice);
        self::assertSame('20.0000', $product->discount);
        self::assertSame("  description\n", $product->description);
        self::assertSame(['Zero' => '0', 'Text' => '  exact value  '], $product->attributes);
    }

    public function testNumericIdentifierWithZeroMaskIsPreservedAndBlankRowsAreSkipped(): void
    {
        $book = $this->workbook();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([123, 'Product', 100.25, 125.00], null, 'A2', true);
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('000000');
        $sheet->fromArray(['xyz', 'Other', 10, 0], null, 'A4', true);
        $rows = (new ExcelReader())->read($this->save($book));
        self::assertCount(2, $rows);
        self::assertSame('000123', $rows[0]['data']?->externalCode);
        self::assertSame('-24.6883', $rows[0]['data']?->discount);
        self::assertSame(4, $rows[1]['row']);
    }

    public function testInvalidRowDoesNotPreventLaterValidProduct(): void
    {
        $book = $this->workbook();
        $book->getActiveSheet()->fromArray([
            ['bad', 'Product', 'not a price', 3],
            ['zero', 'Product', 0, 3],
            ['good', 'Product', 10, 3],
        ], null, 'A2', true);
        $rows = (new ExcelReader())->read($this->save($book));
        self::assertCount(3, $rows);
        self::assertNull($rows[0]['data']);
        self::assertNotEmpty($rows[0]['error']);
        self::assertNull($rows[1]['data']);
        self::assertNotEmpty($rows[1]['error']);
        self::assertSame('good', $rows[2]['data']?->externalCode);
    }

    public function testFormattedNumericIdentifierPreservesMaskSeparatorsAndSections(): void
    {
        $book = $this->workbook();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([123, 'Product', 10, 3], null, 'A2', true);
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('000-000;[Red]000-000');
        $rows = (new ExcelReader())->read($this->save($book));
        self::assertSame('000-123', $rows[0]['data']?->externalCode);
    }

    public function testRejectsFormulaWithoutCalculatingAndContinuesRows(): void
    {
        $book = $this->workbook();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['one', 'Product', 10, 3], ['two', 'Product', 10, 3]], null, 'A2', true);
        $sheet->setCellValue('C2', '=1+9');
        $rows = (new ExcelReader())->read($this->save($book));
        self::assertNull($rows[0]['data']);
        self::assertStringContainsString('формул', (string) $rows[0]['error']);
        self::assertSame('two', $rows[1]['data']?->externalCode);
    }

    public function testRejectsScientificAndLongNumericIdentifiers(): void
    {
        $book = $this->workbook();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([[1.2e12, 'Product', 10, 3], [1234567890123456, 'Product', 10, 3]], null, 'A2', true);
        $sheet->setCellValueExplicit('A3', 1234567890123456, DataType::TYPE_NUMERIC);
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('0.00E+00');
        $rows = (new ExcelReader())->read($this->save($book));
        self::assertNull($rows[0]['data']);
        self::assertNull($rows[1]['data']);
    }

    public function testMissingRequiredHeaderIsStructuralError(): void
    {
        $book = $this->workbook();
        $book->getActiveSheet()->setCellValue('D1', 'Wrong purchase heading');
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read($this->save($book));
    }

    public function testDuplicateNormalizedAttributeHeadersAreStructuralError(): void
    {
        $book = $this->workbook();
        $book->getActiveSheet()->setCellValue('G1', 'Доп. поле Zero');
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read($this->save($book));
    }

    public function testMoreThanOneNonemptySheetIsStructuralError(): void
    {
        $book = $this->workbook();
        $book->createSheet()->setCellValue('A1', 'unexpected content');
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read($this->save($book));
    }

    public function testCorruptZipIsRejected(): void
    {
        $file = $this->temporaryFile();
        file_put_contents($file, 'not a zip workbook');
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read($file);
    }

    public function testXmlEntityDeclarationsAreRejectedBeforeLoadingWorkbook(): void
    {
        $file = $this->save($this->workbook());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $zip->addFromString('xl/unsafe.xml', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><x>&x;</x>');
        $zip->close();
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read($file);
    }

    public function testLargeDeclaredRowIndicesAreRejectedBeforeLoadingWorkbook(): void
    {
        $file = $this->save($this->workbook());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1048576"><c r="A1048576" t="inlineStr"><is><t>hello</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read($file);
    }

    public function testExpandedArchiveLimitIsEnforcedBeforeSpreadsheetLoad(): void
    {
        $file = $this->save($this->workbook());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $zip->addFromString('xl/oversized.bin', str_repeat('x', 50 * 1024 * 1024));
        $zip->close();
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read($file);
    }

    public function testExternalMediaRelationshipsAreRejectedBeforeSpreadsheetLoad(): void
    {
        $file = $this->save($this->workbook());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        $zip->addFromString('xl/_rels/unsafe.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="media" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="http://127.0.0.1/secret" TargetMode="External"/></Relationships>');
        $zip->close();
        $this->expectException(InvalidArgumentException::class);
        (new ExcelReader())->read($file);
    }

    public function testRejectsPricesThatWouldLoseFractionalPrecisionOrExceedDatabaseLimit(): void
    {
        $book = $this->workbook();
        $book->getActiveSheet()->fromArray([
            ['fractional', 'Product', '1,001', 0],
            ['overflow', 'Product', '1000000000000,00', 0],
            ['zero trailing', 'Product', '1,000', 0],
        ], null, 'A2', true);
        $rows = (new ExcelReader())->read($this->save($book));
        self::assertNull($rows[0]['data']);
        self::assertNull($rows[1]['data']);
        self::assertSame('1.00', $rows[2]['data']?->price);
    }

    public function testTextThatStartsWithEqualsRemainsText(): void
    {
        $book = $this->workbook();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['code', 'Product', 10, 3], null, 'A2', true);
        $sheet->setCellValueExplicit('E2', '=literal description', DataType::TYPE_STRING);
        $rows = (new ExcelReader())->read($this->save($book));
        self::assertSame('=literal description', $rows[0]['data']?->description);
    }

    public function testSparseWorkbookDoesNotAllocateBlankCellsAcrossFullRectangle(): void
    {
        $book = $this->workbook();
        $book->getActiveSheet()->setCellValue('GR201', 'ignored column, incomplete product');
        $file = $this->save($book);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);
        $rows = (new ExcelReader())->read($file);
        self::assertCount(1, $rows);
        self::assertSame(201, $rows[0]['row']);
        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testNumericAdditionalFieldNameIsPreservedAsText(): void
    {
        $book = $this->workbook();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('F1', 'Доп. поле: 0');
        $sheet->fromArray(['code', 'Product', 10, 3, '', 'value'], null, 'A2', true);
        $rows = (new ExcelReader())->read($this->save($book));
        self::assertSame(['0' => 'value'], $rows[0]['data']?->attributes);
    }

    public function testDenseCellsWithOmittedCoordinatesAreRejectedDuringPreflight(): void
    {
        $file = $this->save($this->workbook());
        $xml = $this->denseWorksheet(501);
        $this->replaceArchiveEntry($file, 'xl/worksheets/sheet1.xml', $xml);
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testCellBudgetAcceptsBoundaryWithoutExpandingPhpSpreadsheetObjects(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/worksheets/sheet1.xml', $this->denseWorksheet(500));
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);
        $this->preflight($file);
        self::assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
    }

    public function testCellBudgetIncludesUnusedWorksheets(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/worksheets/sheet1.xml', $this->denseWorksheet(300));
        $this->replaceArchiveEntry($file, 'xl/worksheets/unused.xml', $this->denseWorksheet(205));
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testCellBudgetIncludesWorksheetsAtNonstandardXmlPaths(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/custom-data.xml', $this->denseWorksheet(501));
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testWorksheetRelationshipCannotHideXmlUnderBinaryExtension(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="sheet" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="custom-data.bin"/></Relationships>');
        $this->replaceArchiveEntry($file, 'xl/custom-data.bin', $this->denseWorksheet(501));
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testInferredColumnsCannotExceedColumnLimit(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row>' . str_repeat('<c><v>1</v></c>', 201) . '</row></sheetData></worksheet>');
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testInferredRowsCannotExceedProductRowLimit(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . str_repeat('<row><c><v>1</v></c></row>', 10002) . '</sheetData></worksheet>');
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testLargeXmlIsRejectedBeforeParserAllocatesIt(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/large.xml', '<large>' . str_repeat('x', 20 * 1024 * 1024) . '</large>');
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testXmlNodeBudgetIncludesUnreferencedMetadata(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/metadata.xml', '<metadata>' . str_repeat('<n/>', 1000001) . '</metadata>');
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testStyleRecordsHaveSeparateAllocationBudget(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs>' . str_repeat('<xf/>', 4097) . '</cellXfs></styleSheet>');
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testSharedStringsHaveSeparateAllocationBudget(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . str_repeat('<si><t>x</t></si>', 100001) . '</sst>');
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    public function testRichTextRunsHaveSeparateObjectAllocationBudget(): void
    {
        $file = $this->save($this->workbook());
        $this->replaceArchiveEntry($file, 'xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si>' . str_repeat('<r><t>x</t></r>', 100001) . '</si></sst>');
        $this->expectException(InvalidArgumentException::class);
        $this->preflight($file);
    }

    #[RunInSeparateProcess]
    public function testWriterGeneratedDenseTextExportSchemaCanBeReimportedAtCellBudget(): void
    {
        ini_set('memory_limit', '512M');
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $headers = ['Внешний код', 'Наименование', 'Описание', 'Цена: Цена продажи', 'Закупочная цена', 'Скидка'];
        for ($key = 1; $key <= 194; ++$key) {
            $headers[] = 'Доп. поле: K' . $key;
        }
        foreach ($headers as $column => $heading) {
            $sheet->setCellValueExplicit([$column + 1, 1], $heading, DataType::TYPE_STRING);
        }
        for ($row = 2; $row <= 500; ++$row) {
            for ($column = 1; $column <= 200; ++$column) {
                $value = in_array($column, [4, 5, 6], true) ? 100 : $row . '_' . $column;
                $sheet->setCellValueExplicit([$column, $row], $value, in_array($column, [4, 5, 6], true) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:GR1')->getFont()->setBold(true);
        $file = $this->save($book);
        gc_collect_cycles();
        $rows = (new ExcelReader())->read($file);
        self::assertCount(499, $rows);
        self::assertSame('2_1', $rows[0]['data']?->externalCode);
        self::assertSame('500_200', $rows[498]['data']?->attributes['K194']);
        self::assertLessThan(384 * 1024 * 1024, memory_get_peak_usage(true));
    }

    private function denseWorksheet(int $rows): string
    {
        return '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . str_repeat('<row>' . str_repeat('<c><v>1</v></c>', 200) . '</row>', $rows)
            . '</sheetData></worksheet>';
    }

    private function replaceArchiveEntry(string $file, string $name, string $contents): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        self::assertTrue($zip->addFromString($name, $contents));
        self::assertTrue($zip->close());
    }

    private function preflight(string $file): void
    {
        // Resource limits must be checked before loading the workbook into memory.
        $method = new \ReflectionMethod(ExcelReader::class, 'validateArchive');
        $method->invoke(new ExcelReader(), $file);
    }

    private function workbook(): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([
            'Внешний код', 'Наименование', 'Цена: Цена продажи', 'Закупочная цена',
            'Описание', 'Доп. поле: Zero', 'Доп. поле: Empty', 'Доп. поле Text',
        ]);

        return $book;
    }

    private function save(Spreadsheet $book): string
    {
        $file = $this->temporaryFile();
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();

        return $file;
    }

    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'import-xlsx-');
        self::assertNotFalse($file);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
