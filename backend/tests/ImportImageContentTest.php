<?php

declare(strict_types=1);

namespace Tests;

use App\Import\RasterImageValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImportImageContentTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'import-raster-');
        self::assertNotFalse($file);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testRecognizesRasterBytesIndependentlyOfFileExtension(): void
    {
        $raster = imagecreatetruecolor(2, 3);
        self::assertNotFalse($raster);
        imagepng($raster, $this->file);
        self::assertSame('png', (new RasterImageValidator())->extensionFor($this->file));
    }

    public function testRejectsHtmlContentEvenWhenItClaimsToBeAnImage(): void
    {
        file_put_contents($this->file, '<html><body>not an image</body></html>');
        $this->expectException(InvalidArgumentException::class);
        (new RasterImageValidator())->extensionFor($this->file);
    }

    public function testRejectsTruncatedRaster(): void
    {
        file_put_contents($this->file, "\x89PNG\r\n\x1a\n");
        $this->expectException(InvalidArgumentException::class);
        (new RasterImageValidator())->extensionFor($this->file);
    }

    public function testRejectsHugeDimensionsBeforeRasterDecode(): void
    {
        $header = pack('NNCCCCC', 100000, 100000, 8, 2, 0, 0, 0);
        $chunk = 'IHDR' . $header;
        file_put_contents($this->file, "\x89PNG\r\n\x1a\n" . pack('N', strlen($header)) . $chunk . pack('N', crc32($chunk)));
        $this->expectException(InvalidArgumentException::class);
        (new RasterImageValidator())->extensionFor($this->file);
    }
}
