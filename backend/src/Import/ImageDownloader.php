<?php

declare(strict_types=1);

namespace App\Import;

interface ImageDownloader
{
    /** @return array{url: string, path: string} */
    public function download(string $url): array;

    public function remove(string $path): void;
}
