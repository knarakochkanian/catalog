<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\Column(name: 'external_code', length: 255, unique: true)]
    public string $externalCode = '';
    #[ORM\Column(length: 255)]
    public string $name = '';
    #[ORM\Column(type: 'text')]
    public string $description = '';
    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    public string $price = '0.00';
    #[ORM\Column(name: 'purchase_price', type: 'decimal', precision: 14, scale: 2)]
    public string $purchasePrice = '0.00';
    #[ORM\Column(type: 'decimal', precision: 16, scale: 4)]
    public string $discount = '0.0000';
    /** @var Collection<int, ProductAttribute> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductAttribute::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $attributes;
    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductImage::class, cascade: ['persist'], orphanRemoval: true)]
    public Collection $images;
    public function __construct()
    {
        $this->attributes = new ArrayCollection();
        $this->images = new ArrayCollection();
    }
}
