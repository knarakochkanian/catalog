<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExportService
{
    public function __construct(private ProductRepository $products, private string $storage)
    {
    }
    public function export(): string
    {
        $products = $this->products->all();
        $keys = [];
        foreach ($products as $product) {
            foreach ($product->attributes as $attr) {
                $keys[$attr->key] = true;
            }
        }
        $names = array_map(static fn (string|int $key) => (string)$key, array_keys($keys));
        sort($names, SORT_STRING);
        $headers = array_merge(['Внешний код','Наименование','Описание','Цена: Цена продажи','Закупочная цена','Скидка'], array_map(fn (string $key) => 'Доп. поле: '.$key, $names));
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Товары');
        foreach ($headers as $col => $header) {
            $sheet->setCellValueExplicit([$col + 1,1], $header, DataType::TYPE_STRING);
        }
        foreach ($products as $index => $product) {
            $values = [$product->externalCode,$product->name,$product->description,$product->price,$product->purchasePrice,$product->discount];
            $attributes = [];
            foreach ($product->attributes as $attr) {
                $attributes[$attr->key] = $attr->value;
            }
            foreach ($names as $key) {
                $values[] = $attributes[$key] ?? '';
            }
            foreach ($values as $col => $value) {
                $sheet->setCellValueExplicit([$col + 1,$index + 2], $value, in_array($col, [3,4,5], true) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        $file = $this->storage.'/export-'.bin2hex(random_bytes(16)).'.xlsx';
        try {
            (new Xlsx($workbook))->save($file);
        } catch (\Throwable $e) {
            if (is_file($file)) {
                unlink($file);
            } throw $e;
        } finally {
            $workbook->disconnectWorksheets();
        }
        return $file;
    }
}
