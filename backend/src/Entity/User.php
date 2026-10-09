<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name:'users')]
class User
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\Column(length:100, unique:true)]
    public string $username = '';
    #[ORM\Column(name:'password_hash', length:255)]
    public string $passwordHash = '';
}
