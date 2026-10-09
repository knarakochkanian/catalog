<?php

declare(strict_types=1);

namespace Tests;

use App\Import\SafeImageDownloader;
use App\Import\DnsResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportSafeImageDownloaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/import-images-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/images/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory . '/images')) {
            rmdir($this->directory . '/images');
        }
        rmdir($this->directory);
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsUnsafeUrlsBeforeAnyNetworkConnection(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SafeImageDownloader($this->directory))->download($url);
    }

    /** @return array<string, array{string}> */
    public static function unsafeUrls(): array
    {
        return [
            'local v4' => ['http://127.0.0.1/image.png'],
            'metadata' => ['http://169.254.169.254/latest/meta-data'],
            'private' => ['https://192.168.1.1/image.png'],
            'loopback v6' => ['http://[::1]/image.png'],
            'mapped v4' => ['http://[::ffff:127.0.0.1]/image.png'],
            'NAT64' => ['http://[64:ff9b::7f00:1]/image.png'],
            'multicast v4' => ['http://224.0.0.1/image.png'],
            'multicast v6' => ['http://[ff02::1]/image.png'],
            'decimal v4' => ['http://2130706433/image.png'],
            'hex v4' => ['http://0x7f000001/image.png'],
            'octal v4' => ['http://0177.0.0.1/image.png'],
            'userinfo' => ['http://user:password@example.com/image.png'],
            'custom port' => ['http://example.com:8080/image.png'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher' => ['gopher://127.0.0.1/'],
            'local hostname' => ['http://localhost/image.png'],
        ];
    }

    public function testRemoveRejectsTraversalAndLeavesOutsideFilesUntouched(): void
    {
        $sentinel = $this->directory . '/outside.png';
        file_put_contents($sentinel, 'keep');
        try {
            (new SafeImageDownloader($this->directory))->remove('../outside.png');
            self::fail('Traversal should be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame('keep', file_get_contents($sentinel));
        } finally {
            unlink($sentinel);
        }
    }

    public function testMixedPublicAndPrivateDnsAnswersRejectEntireDomain(): void
    {
        $resolver = new class () implements DnsResolver {
            public function resolve(string $host, float $timeoutSeconds): array
            {
                return ['8.8.8.8', '127.0.0.1'];
            }
        };
        $this->expectException(InvalidArgumentException::class);
        (new SafeImageDownloader($this->directory, $resolver))->download('https://example.com/image.png');
    }

    public function testRemoveAcceptsOnlyGeneratedBasename(): void
    {
        $downloader = new SafeImageDownloader($this->directory);
        $basename = str_repeat('a', 32) . '.png';
        file_put_contents($this->directory . '/images/' . $basename, 'image');
        $downloader->remove($basename);
        self::assertFileDoesNotExist($this->directory . '/images/' . $basename);
    }
}
