<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity,ORM\Table(name:'rate_limits')]
class RateLimit
{
    #[ORM\Id,ORM\Column(length:100)]
    public string $bucket = '';
    #[ORM\Column]
    public int $hits = 0;
    #[ORM\Column(name:'created_at')]
    public int $createdAt = 0;
}
