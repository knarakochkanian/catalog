<?php

declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use XMLReader;
use ZipArchive;

final class ExcelReader
{
    // Eager PhpSpreadsheet allocation needs limits beyond ZIP size and rectangular dimensions.
    public const MAX_CELLS = 100000;
    public const MAX_XML_BYTES = 20 * 1024 * 1024;
    public const MAX_XML_NODES = 1000000;
    public const MAX_STYLE_RECORDS = 4096;
    public const MAX_SHARED_STRINGS = 100000;
    public const MAX_RICH_TEXT_RUNS = 100000;

    private const REQUIRED_HEADERS = ['Внешний код', 'Наименование', 'Цена: Цена продажи', 'Закупочная цена'];
    private const MAX_ROWS = 10000;
    private const MAX_COLUMNS = 200;
    private const MAX_EXPANDED_BYTES = 50 * 1024 * 1024;
    private const MAX_ZIP_ENTRIES = 2048;

    /** @return list<array{sheet: string, row: int, data: ?ProductData, error: ?string}> */
    public function read(string $file): array
    {
        $this->validateArchive($file);
        $reader = new Xlsx();
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);

        try {
            $book = $reader->load($file);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Не удалось прочитать структуру XLSX.', 0, $exception);
        }

        try {
            $nonempty = [];
            foreach ($book->getAllSheets() as $sheet) {
                if ($this->isNonempty($sheet)) {
                    $nonempty[] = $sheet;
                }
            }
            if (count($nonempty) !== 1) {
                throw new InvalidArgumentException('Файл должен содержать один непустой лист.');
            }

            $sheet = $nonempty[0];
            $lastRow = $sheet->getHighestDataRow();
            $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($lastRow > self::MAX_ROWS + 1 || $lastColumn > self::MAX_COLUMNS) {
                throw new InvalidArgumentException('Превышен лимит 10 000 товаров или 200 колонок.');
            }
            [$headers, $attributeColumns] = $this->headers($sheet, $lastColumn);
            $result = [];
            for ($row = 2; $row <= $lastRow; ++$row) {
                if ($this->isBlankRow($sheet, $row, $lastColumn)) {
                    continue;
                }
                try {
                    $data = $this->product($sheet, $row, $headers, $attributeColumns);
                    $result[] = ['sheet' => $sheet->getTitle(), 'row' => $row, 'data' => $data, 'error' => null];
                } catch (InvalidArgumentException $exception) {
                    $result[] = ['sheet' => $sheet->getTitle(), 'row' => $row, 'data' => null, 'error' => $exception->getMessage()];
                }
            }

            return $result;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function validateArchive(string $file): void
    {
        $zip = new ZipArchive();
        if (!is_file($file) || $zip->open($file, ZipArchive::CHECKCONS) !== true) {
            throw new InvalidArgumentException('Файл не является корректным ZIP/XLSX.');
        }
        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new InvalidArgumentException('Некорректная структура или слишком много элементов XLSX.');
            }
            $total = 0;
            $xmlBytes = 0;
            $usage = ['cells' => 0, 'nodes' => 0, 'styles' => 0, 'strings' => 0, 'runs' => 0];
            $names = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new InvalidArgumentException('Повреждённый элемент XLSX.');
                }
                $name = $stat['name'];
                if (isset($names[$name])) {
                    throw new InvalidArgumentException('Повторяющееся имя элемента ZIP/XLSX.');
                }
                $names[$name] = true;
                if (str_starts_with($name, '/') || str_contains($name, '\\') || in_array('..', explode('/', $name), true)) {
                    throw new InvalidArgumentException('Небезопасное имя элемента XLSX.');
                }
                $total += $stat['size'];
                if ($total > self::MAX_EXPANDED_BYTES) {
                    throw new InvalidArgumentException('Распакованный XLSX превышает 50 MiB.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new InvalidArgumentException('Не удалось распаковать элемент XLSX.');
                }
                $actual = 0;
                $xml = '';
                $isXml = str_ends_with(strtolower($name), '.xml') || str_ends_with(strtolower($name), '.rels');
                if ($isXml) {
                    $xmlBytes += $stat['size'];
                    if ($xmlBytes > self::MAX_XML_BYTES) {
                        fclose($stream);
                        throw new InvalidArgumentException('Суммарный XML в XLSX превышает 20 MiB.');
                    }
                }
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 8192);
                        if ($chunk === false) {
                            throw new InvalidArgumentException('Повреждённый элемент XLSX.');
                        }
                        $actual += strlen($chunk);
                        if ($actual > $stat['size'] || $actual > self::MAX_EXPANDED_BYTES) {
                            throw new InvalidArgumentException('Превышен размер элемента XLSX.');
                        }
                        if ($isXml) {
                            $xml .= $chunk;
                        }
                    }
                } finally {
                    fclose($stream);
                }
                if ($actual !== $stat['size']) {
                    throw new InvalidArgumentException('Размер элемента XLSX не совпадает.');
                }
                if ($isXml) {
                    $this->validateXml($name, $xml, $usage);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @param array{cells: int, nodes: int, styles: int, strings: int, runs: int} $usage */
    private function validateXml(string $name, string $xml, array &$usage): void
    {
        // XMLReader sees declarations even when the workbook uses UTF-16.
        $reader = new XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$reader->XML($xml, null, LIBXML_NONET)) {
                throw new InvalidArgumentException('Повреждённый XML в XLSX.');
            }
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $worksheet = str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml');
            $rowIndex = 0;
            $columnIndex = 0;
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE || $reader->nodeType === XMLReader::ENTITY_REF) {
                    throw new InvalidArgumentException('DTD и внешние сущности XML запрещены.');
                }
                if (in_array($reader->nodeType, [XMLReader::ELEMENT, XMLReader::TEXT, XMLReader::CDATA, XMLReader::COMMENT, XMLReader::PI, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    $usage['nodes'] += 1 + ($reader->nodeType === XMLReader::ELEMENT ? $reader->attributeCount : 0);
                    if ($usage['nodes'] > self::MAX_XML_NODES) {
                        throw new InvalidArgumentException('Превышен лимит 1 000 000 XML-узлов и атрибутов XLSX.');
                    }
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                // XML parts may be referenced at nonstandard archive paths.
                $worksheet = $worksheet || in_array($reader->localName, ['worksheet', 'sheetData'], true);
                if (in_array($reader->localName, ['font', 'fill', 'border', 'numFmt', 'xf', 'dxf', 'cellStyle'], true)) {
                    if (++$usage['styles'] > self::MAX_STYLE_RECORDS) {
                        throw new InvalidArgumentException('Превышен лимит 4096 записей стилей XLSX.');
                    }
                }
                if ($reader->localName === 'si' && ++$usage['strings'] > self::MAX_SHARED_STRINGS) {
                    throw new InvalidArgumentException('Превышен лимит 100 000 общих строк XLSX.');
                }
                if ($reader->localName === 'r' && ++$usage['runs'] > self::MAX_RICH_TEXT_RUNS) {
                    throw new InvalidArgumentException('Превышен лимит 100 000 фрагментов форматированного текста XLSX.');
                }
                if ($reader->localName === 'Relationship') {
                    $type = $reader->getAttribute('Type') ?? '';
                    if (($reader->getAttribute('TargetMode') === 'External' && !str_ends_with($type, '/hyperlink'))
                        || preg_match('~/(?:drawing|image|vmlDrawing)$~', $type) === 1) {
                        throw new InvalidArgumentException('Внешние связи и встроенные рисунки XLSX не поддерживаются.');
                    }
                    if (preg_match('~/(?:officeDocument|worksheet|styles|sharedStrings|theme|comments|table|externalLink|calcChain|connections|sheetMetadata|pivotTable|pivotCacheDefinition|pivotCacheRecords|queryTable)$~', $type) === 1
                        && !str_ends_with(strtolower($reader->getAttribute('Target') ?? ''), '.xml')) {
                        throw new InvalidArgumentException('XML-связь XLSX должна указывать на файл .xml.');
                    }
                }
                if (!$worksheet) {
                    continue;
                }
                if ($reader->localName === 'mergeCell') {
                    throw new InvalidArgumentException('Объединённые ячейки не поддерживаются при импорте.');
                }
                if ($reader->localName === 'row') {
                    $row = $reader->getAttribute('r');
                    if ($row !== null && (!ctype_digit($row) || (int) $row < 1 || (int) $row > self::MAX_ROWS + 1)) {
                        throw new InvalidArgumentException('Номер строки превышает лимит XLSX.');
                    }
                    $rowIndex = $row === null ? $rowIndex + 1 : (int) $row;
                    $columnIndex = 0;
                    if ($rowIndex > self::MAX_ROWS + 1) {
                        throw new InvalidArgumentException('Неявный номер строки превышает лимит XLSX.');
                    }
                }
                if ($reader->localName === 'c') {
                    if (++$usage['cells'] > self::MAX_CELLS) {
                        throw new InvalidArgumentException('Превышен суммарный лимит 100 000 ячеек XLSX.');
                    }
                    $reference = $reader->getAttribute('r');
                    if ($reference !== null && preg_match('/^\$?([A-Z]{1,3})\$?[1-9][0-9]*$/', $reference, $match) === 1) {
                        $columnIndex = Coordinate::columnIndexFromString($match[1]);
                    } else {
                        ++$columnIndex;
                    }
                    if ($columnIndex > self::MAX_COLUMNS) {
                        throw new InvalidArgumentException('Неявный номер колонки превышает лимит XLSX.');
                    }
                }
                if ($reader->localName === 'col') {
                    foreach (['min', 'max'] as $attribute) {
                        $column = $reader->getAttribute($attribute);
                        if ($column !== null && (!ctype_digit($column) || (int) $column < 1 || (int) $column > self::MAX_COLUMNS)) {
                            throw new InvalidArgumentException('Номер колонки превышает лимит XLSX.');
                        }
                    }
                }
                foreach (['r', 'ref', 'sqref'] as $attribute) {
                    $reference = $reader->getAttribute($attribute);
                    if ($reference !== null && $reader->localName !== 'row') {
                        foreach (preg_split('/[\s:]+/', $reference) ?: [] as $coordinate) {
                            if (!preg_match('/^\$?([A-Z]+)\$?([1-9][0-9]*)$/', $coordinate, $match)
                                || strlen($match[1]) > 3
                                || Coordinate::columnIndexFromString($match[1]) > self::MAX_COLUMNS
                                || (int) $match[2] > self::MAX_ROWS + 1) {
                                throw new InvalidArgumentException('Диапазон ячеек превышает лимит XLSX.');
                            }
                        }
                    }
                }
            }
            if (libxml_get_errors() !== []) {
                throw new InvalidArgumentException('Повреждённый XML в XLSX.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function isNonempty(Worksheet $sheet): bool
    {
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $value = $sheet->getCell($coordinate)->getValue();
            if ($value !== null && (!is_string($value) || trim($value) !== '')) {
                return true;
            }
        }

        return false;
    }

    private function isBlankRow(Worksheet $sheet, int $row, int $lastColumn): bool
    {
        for ($column = 1; $column <= $lastColumn; ++$column) {
            if (!$sheet->cellExists([$column, $row])) {
                continue;
            }
            $value = $sheet->getCell([$column, $row])->getValue();
            if ($value !== null && (!is_string($value) || trim($value) !== '')) {
                return false;
            }
        }

        return true;
    }

    /** @return array{array<string, int>, array<string, int>} */
    private function headers(Worksheet $sheet, int $lastColumn): array
    {
        $headers = [];
        $attributes = [];
        for ($column = 1; $column <= $lastColumn; ++$column) {
            $heading = trim($this->text($sheet->getCell([$column, 1])));
            if ($heading === '') {
                continue;
            }
            if (isset($headers[$heading])) {
                throw new InvalidArgumentException('Повторяющийся заголовок: ' . $heading);
            }
            $headers[$heading] = $column;
            if (str_starts_with($heading, 'Доп. поле')) {
                $key = trim(ltrim(trim(substr($heading, strlen('Доп. поле'))), ':'));
                if ($key === '' || mb_strlen($key) > 255 || isset($attributes[$key])) {
                    throw new InvalidArgumentException('Пустой, слишком длинный или повторяющийся ключ дополнительного поля.');
                }
                $attributes[$key] = $column;
            }
        }
        foreach (self::REQUIRED_HEADERS as $heading) {
            if (!isset($headers[$heading])) {
                throw new InvalidArgumentException('Отсутствует обязательный заголовок: ' . $heading);
            }
        }

        return [$headers, $attributes];
    }

    /**
     * @param array<string, int> $headers
     * @param array<string, int> $attributeColumns
     */
    private function product(Worksheet $sheet, int $row, array $headers, array $attributeColumns): ProductData
    {
        $code = trim($this->identifier($sheet->getCell([$headers['Внешний код'], $row])));
        $name = trim($this->text($sheet->getCell([$headers['Наименование'], $row])));
        $description = isset($headers['Описание']) ? $this->text($sheet->getCell([$headers['Описание'], $row])) : '';
        $this->boundedText($code, 'Внешний код', 255, true);
        $this->boundedText($name, 'Наименование', 255, true);
        $this->boundedText($description, 'Описание', 10000);
        $price = $this->price($sheet->getCell([$headers['Цена: Цена продажи'], $row]), true);
        $purchasePrice = $this->price($sheet->getCell([$headers['Закупочная цена'], $row]), false);
        $attributes = [];
        $images = [];
        foreach ($attributeColumns as $key => $column) {
            // PHP coerces numeric string map keys to integers; the field name remains textual.
            $key = (string) $key;
            $value = $this->text($sheet->getCell([$column, $row]));
            $this->boundedText($value, $key, 10000);
            if (trim($value) === '') {
                continue;
            }
            $attributes[$key] = $value;
            if (in_array($key, ['Ссылка на упаковку', 'Ссылки на фото'], true)) {
                foreach (preg_split('/[\s,;]+/u', trim($value)) ?: [] as $url) {
                    if (strlen($url) > 2048) {
                        throw new InvalidArgumentException('Ссылка на изображение превышает 2048 байт.');
                    }
                    $images[$url] = $url;
                }
            }
        }
        if (count($images) > 10) {
            throw new InvalidArgumentException('На товар допускается не более 10 изображений.');
        }
        $discount = bcdiv(bcmul(bcsub($price, $purchasePrice, 2), '100', 2), $price, 5);
        $discount = bcadd($discount, str_starts_with($discount, '-') ? '-0.00005' : '0.00005', 4);
        if (bccomp($discount, '-999999999999.9999', 4) < 0 || bccomp($discount, '999999999999.9999', 4) > 0) {
            throw new InvalidArgumentException('Скидка превышает допустимую точность DECIMAL(16,4).');
        }

        return new ProductData($code, $name, $description, $price, $purchasePrice, $discount, $attributes, array_values($images));
    }

    private function text(Cell $cell): string
    {
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            throw new InvalidArgumentException('Ячейки с формулами не допускаются.');
        }
        if ($cell->getDataType() === DataType::TYPE_ERROR) {
            throw new InvalidArgumentException('Ячейка содержит ошибку Excel.');
        }
        $value = $cell->getValue();
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        if ($value === null) {
            return '';
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Неподдерживаемый тип значения ячейки.');
        }

        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    private function identifier(Cell $cell): string
    {
        $text = $this->text($cell);
        $value = $cell->getValue();
        if (is_int($value) || is_float($value)) {
            $format = $cell->getStyle()->getNumberFormat()->getFormatCode();
            if ($value < 0 || !is_finite((float) $value) || floor((float) $value) !== (float) $value
                || strlen(sprintf('%.0F', $value)) > 15 || preg_match('/E[+-]?0/i', $format) === 1) {
                throw new InvalidArgumentException('Внешний код должен быть текстом или точным целым числом без экспоненты.');
            }
            $positiveFormat = explode(';', $format, 2)[0];
            $positiveFormat = preg_replace('/\[[^\]]+\]/', '', $positiveFormat) ?? '';
            if (str_contains($positiveFormat, '0') && preg_match('/^(?:0+|"[^"]*"|\\\\.|[- ()])*$/u', $positiveFormat) === 1) {
                // Format the stored scalar directly: no formula engine is invoked.
                return NumberFormat::toFormattedString($value, $format);
            }

            return sprintf('%.0F', $value);
        }

        return $text;
    }

    private function price(Cell $cell, bool $positive): string
    {
        $text = trim($this->text($cell));
        $value = $cell->getValue();
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value) || abs((float) $value - round((float) $value, 2)) > max(0.0000001, abs((float) $value) * PHP_FLOAT_EPSILON * 2)) {
                throw new InvalidArgumentException('Цена должна иметь не более двух знаков после запятой.');
            }
            $text = number_format((float) $value, 2, '.', '');
        }
        $text = str_replace(',', '.', $text);
        if (preg_match('/^\+?([0-9]+)(?:\.([0-9]+))?$/', $text, $match) !== 1) {
            throw new InvalidArgumentException('Цена должна быть неотрицательным десятичным числом.');
        }
        $integer = ltrim($match[1], '0');
        $fraction = $match[2] ?? '';
        if (strlen($integer) > 12 || trim(substr($fraction, 2), '0') !== '') {
            throw new InvalidArgumentException('Цена превышает точность DECIMAL(14,2).');
        }
        $price = ($integer === '' ? '0' : $integer) . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
        if ($positive && bccomp($price, '0', 2) <= 0) {
            throw new InvalidArgumentException('Цена продажи должна быть больше нуля.');
        }

        return $price;
    }

    private function boundedText(string $value, string $field, int $maximum, bool $required = false): void
    {
        if (($required && $value === '') || mb_strlen($value) > $maximum || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Поле «' . $field . '» пустое, слишком длинное или содержит недопустимый текст.');
        }
    }
}
