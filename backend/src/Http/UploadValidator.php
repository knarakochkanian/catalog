<?php

declare(strict_types=1);

namespace App\Http;

final class UploadValidator
{
    public function validate(string $path, string $originalName): void
    {
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \InvalidArgumentException('Выберите файл с расширением .xlsx.');
        }
        $size = filesize($path);
        if ($size === false || $size === 0 || $size > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException('Размер файла должен быть от 1 байта до 10 MiB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
            throw new \InvalidArgumentException('Файл не является документом XLSX.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('Повреждённый XLSX-файл.');
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new \InvalidArgumentException('ZIP не содержит структуру XLSX.');
            }
        } finally {
            $zip->close();
        }
    }
}
