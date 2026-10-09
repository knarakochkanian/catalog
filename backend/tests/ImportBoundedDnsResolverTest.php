<?php

declare(strict_types=1);

namespace Tests;

use App\Import\BoundedDnsResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImportBoundedDnsResolverTest extends TestCase
{
    public function testExpiredDeadlineDoesNotStartResolution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BoundedDnsResolver())->resolve('example.com', 0);
    }

    public function testNonexistentDnsNameFinishesWithinBoundedDeadline(): void
    {
        $start = microtime(true);
        try {
            (new BoundedDnsResolver())->resolve('product-import-does-not-exist.invalid', 0.05);
            self::fail('A reserved invalid domain cannot produce an address.');
        } catch (InvalidArgumentException) {
            self::assertLessThan(1.0, microtime(true) - $start);
        }
    }
}
