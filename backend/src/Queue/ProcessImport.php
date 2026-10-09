<?php

declare(strict_types=1);

namespace App\Queue;

final readonly class ProcessImport
{
    public function __construct(public string $id)
    {
    }
}
