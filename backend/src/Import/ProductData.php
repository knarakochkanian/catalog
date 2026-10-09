<?php

declare(strict_types=1);

namespace App\Import;

final readonly class ProductData
{
    /**
     * @param array<string, string> $attributes
     * @param list<string> $imageUrls
     */
    public function __construct(
        public string $externalCode,
        public string $name,
        public string $description,
        public string $price,
        public string $purchasePrice,
        public string $discount,
        public array $attributes,
        public array $imageUrls,
    ) {
    }
}
