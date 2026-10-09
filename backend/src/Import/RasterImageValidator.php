<?php

declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;
use RuntimeException;

final class RasterImageValidator
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function extensionFor(string $file): string
    {
        $size = filesize($file);
        if ($size === false) {
            throw new RuntimeException('Временное изображение недоступно.');
        }
        if ($size < 1 || $size > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Изображение пустое или превышает 5 MiB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if ($mime === false || !isset(self::EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('Допускаются только JPEG, PNG, WebP и GIF.');
        }
        $dimensions = @getimagesize($file);
        if ($dimensions === false || $dimensions['mime'] !== $mime
            || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] * $dimensions[1] > 25000000) {
            throw new InvalidArgumentException('Изображение повреждено или превышает 25 мегапикселей.');
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Не удалось прочитать временное изображение.');
        }
        // Decode after checking dimensions; content type and a raster signature alone are insufficient.
        $raster = @imagecreatefromstring($contents);
        if ($raster === false) {
            throw new InvalidArgumentException('Не удалось декодировать изображение.');
        }

        return self::EXTENSIONS[$mime];
    }
}
