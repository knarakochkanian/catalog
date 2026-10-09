<?php

declare(strict_types=1);

namespace App\Import;

interface DnsResolver
{
    /** @return list<string> */
    public function resolve(string $host, float $timeoutSeconds): array;
}
