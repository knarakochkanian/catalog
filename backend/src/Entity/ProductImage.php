<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_images')]
class ProductImage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\Column(type: 'text')]
    public string $url;
    #[ORM\Column(length: 255)]
    public string $path;
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Product $product;
    public function __construct(Product $product, string $url, string $path)
    {
        $this->product = $product;
        $this->url = $url;
        $this->path = $path;
    }
}
