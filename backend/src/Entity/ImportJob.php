<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name:'import_jobs')]
class ImportJob
{
    #[ORM\Id, ORM\Column(length:32)]
    public string $id = '';
    #[ORM\Column(name:'owner_id')]
    public int $ownerId = 0;
    #[ORM\Column(length:255)]
    public string $filename = '';
    #[ORM\Column(length:20)]
    public string $status = 'queued';
    #[ORM\Column]
    public int $total = 0;
    #[ORM\Column]
    public int $processed = 0;
    #[ORM\Column]
    public int $created = 0;
    #[ORM\Column]
    public int $updated = 0;
    #[ORM\Column]
    public int $skipped = 0;
    /** @var list<array{sheet:string,row:int,message:string}> */
    #[ORM\Column(type:'json')]
    public array $errors = [];
    #[ORM\Column(type:'text', nullable:true)]
    public ?string $message = null;
    #[ORM\Column(name:'created_at')]
    public int $createdAt = 0;
    #[ORM\Column(name:'published_at', nullable:true)]
    public ?int $publishedAt = null;
}
