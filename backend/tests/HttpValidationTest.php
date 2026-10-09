<?php

declare(strict_types=1);

namespace Tests;

use App\Database;
use App\Entity\RateLimit;
use App\Http\UploadValidator;
use App\Http\RateLimiter;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class HttpValidationTest extends TestCase
{
    public function testAcceptsOnlyRealXlsx(): void
    {
        (new UploadValidator())->validate(__DIR__.'/fixtures/products.xlsx', 'products.xlsx');
        self::assertTrue(true);
        $this->expectException(\InvalidArgumentException::class);
        (new UploadValidator())->validate(__DIR__.'/fixtures/products.xlsx', 'products.php');
    }
    public function testRejectsCorruptXlsx(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'invalid');
        file_put_contents($file, 'fake xlsx');
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new UploadValidator())->validate($file, 'broken.xlsx');
        } finally {
            unlink($file);
        }
    }
    public function testRateLimitUsesDurableAtomicCounter(): void
    {
        $db = new Database('sqlite:///:memory:');
        $em = $db->entityManager();
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(RateLimit::class)]);
        $limiter = new RateLimiter($db);
        self::assertTrue($limiter->allow('import:1', 2, 60));
        self::assertTrue($limiter->allow('import:1', 2, 60));
        self::assertFalse($limiter->allow('import:1', 2, 60));
        self::assertTrue($limiter->allow('import:2', 2, 60));
    }
}
