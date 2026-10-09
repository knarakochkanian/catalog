<?php

declare(strict_types=1);

namespace Tests;

use App\Bootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testProcessEnvironmentWinsWhenEnvSuperglobalsAreEmpty(): void
    {
        $server = $_SERVER;
        $environment = $_ENV;
        $origin = getenv('APP_ORIGIN');
        $file = tempnam(sys_get_temp_dir(), 'env-test');
        file_put_contents($file, "APP_ORIGIN=http://other.test:8080\n");
        try {
            $_SERVER = [];
            $_ENV = [];
            putenv('APP_ORIGIN=http://example.test:4200');
            Bootstrap::loadEnvironment($file);
            self::assertSame('http://example.test:4200', Bootstrap::env('APP_ORIGIN'));
        } finally {
            $_SERVER = $server;
            $_ENV = $environment;
            putenv($origin === false ? 'APP_ORIGIN' : 'APP_ORIGIN='.$origin);
            unlink($file);
        }
    }
}
