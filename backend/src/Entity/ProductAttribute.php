<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_attributes')]
#[ORM\UniqueConstraint(name: 'attribute_product_key', columns: ['product_id', 'attribute_key'])]
class ProductAttribute
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\Column(name: 'attribute_key', length: 255)]
    public string $key;
    #[ORM\Column(type: 'text')]
    public string $value;
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'attributes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Product $product;
    public function __construct(Product $product, string $key, string $value)
    {
        $this->product = $product;
        $this->key = $key;
        $this->value = $value;
    }
}
