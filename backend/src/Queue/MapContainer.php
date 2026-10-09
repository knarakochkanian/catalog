<?php

declare(strict_types=1);

namespace App\Queue;

use Psr\Container\ContainerInterface;

final class MapContainer implements ContainerInterface
{
    /** @param array<string,object> $services */
    public function __construct(private array $services)
    {
    }
    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new \RuntimeException('Unknown service.');
    }
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
