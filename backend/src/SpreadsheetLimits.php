<?php

declare(strict_types=1);

namespace App;

final class SpreadsheetLimits
{
    public const MAX_CELLS = 100000;
    public const MAX_PRODUCTS = 10000;
    public const MAX_ATTRIBUTES = 194;
    public const MAX_TEXT_BYTES = 2 * 1024 * 1024;
    public static function assertExportable(int $products, int $attributes, int $textBytes = 0): void
    {
        if ($attributes > self::MAX_ATTRIBUTES) {
            throw new \InvalidArgumentException('В каталоге допускается до 194 различных дополнительных полей, чтобы экспорт можно было импортировать обратно.');
        }
        if ($products > self::MAX_PRODUCTS || ($products + 1) * (6 + $attributes) > self::MAX_CELLS) {
            throw new \InvalidArgumentException('Каталог превышает лимит 100 000 ячеек экспортируемого файла. Уменьшите число товаров или дополнительных полей.');
        }
        if ($textBytes > self::MAX_TEXT_BYTES) {
            throw new \InvalidArgumentException('Общий объём текста каталога превышает 2 MiB. Уменьшите описания или дополнительные поля, чтобы сохранить возможность повторного импорта экспорта.');
        }
    }
}
