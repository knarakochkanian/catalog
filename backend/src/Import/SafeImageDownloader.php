<?php

declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SafeImageDownloader implements ImageDownloader
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private readonly string $imageDirectory;
    private readonly DnsResolver $resolver;

    public function __construct(string $storageDir, ?DnsResolver $resolver = null)
    {
        $directory = rtrim($storageDir, DIRECTORY_SEPARATOR) . '/images';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new RuntimeException('Не удалось создать хранилище изображений.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_writable($resolved)) {
            throw new RuntimeException('Хранилище изображений недоступно.');
        }
        $this->imageDirectory = $resolved;
        $this->resolver = $resolver ?? new BoundedDnsResolver();
    }

    /** @return array{url: string, path: string} */
    public function download(string $url): array
    {
        $deadline = microtime(true) + 10;
        [$host, $port, $address] = $this->validatedTarget($url, $deadline);
        $temporary = tempnam($this->imageDirectory, '.download-');
        if ($temporary === false) {
            throw new RuntimeException('Не удалось создать временное изображение.');
        }
        $stream = fopen($temporary, 'wb');
        if ($stream === false) {
            unlink($temporary);
            throw new RuntimeException('Не удалось записать изображение.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            fclose($stream);
            unlink($temporary);
            throw new RuntimeException('Не удалось инициализировать загрузку изображения.');
        }
        $bytes = 0;
        $tooLarge = false;
        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $resolveAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $remainingMilliseconds = max(1, (int) (($deadline - microtime(true)) * 1000));
        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remainingMilliseconds),
            CURLOPT_TIMEOUT_MS => $remainingMilliseconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ProductImport/1.0',
            CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/webp,image/gif'],
            CURLOPT_RESOLVE => $isLiteral ? [] : [$host . ':' . $port . ':' . $resolveAddress],
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $data) use ($stream, &$bytes, &$tooLarge): int {
                $length = strlen($data);
                $bytes += $length;
                if ($bytes > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0;
                }
                $written = fwrite($stream, $data);

                return $written === false ? 0 : $written;
            },
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $header) use (&$tooLarge): int {
                if (preg_match('/^Content-Length:\s*([0-9]+)/i', $header, $match) === 1 && (float) $match[1] > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0;
                }

                return strlen($header);
            },
        ]);

        try {
            $success = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $connectedAddress = curl_getinfo($curl, CURLINFO_PRIMARY_IP);
            if ($tooLarge) {
                throw new InvalidArgumentException('Изображение превышает 5 MiB.');
            }
            if ($success === false) {
                throw new InvalidArgumentException('Ошибка загрузки изображения: ' . curl_error($curl));
            }
            if ($status !== 200) {
                throw new InvalidArgumentException('Сервер изображения вернул HTTP ' . $status . '; перенаправления запрещены.');
            }
            if (inet_pton($connectedAddress) !== inet_pton($address)) {
                throw new InvalidArgumentException('Адрес сервера изображения изменился.');
            }
            fclose($stream);
            $stream = null;
            $extension = (new RasterImageValidator())->extensionFor($temporary);
            $basename = bin2hex(random_bytes(16)) . '.' . $extension;
            if (!rename($temporary, $this->imageDirectory . '/' . $basename)) {
                throw new RuntimeException('Не удалось сохранить изображение.');
            }
            chmod($this->imageDirectory . '/' . $basename, 0600);

            return ['url' => $url, 'path' => $basename];
        } catch (Throwable $exception) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            curl_close($curl);
        }
    }

    public function remove(string $path): void
    {
        if (preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/D', $path) !== 1) {
            throw new InvalidArgumentException('Небезопасное имя изображения.');
        }
        $file = $this->imageDirectory . '/' . $path;
        if (is_link($file)) {
            throw new InvalidArgumentException('Символические ссылки в хранилище запрещены.');
        }
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException('Не удалось удалить изображение.');
        }
    }

    /** @return array{string, int, string} */
    private function validatedTarget(string $url, float $deadline): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new InvalidArgumentException('Недопустимый URL изображения.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Допускаются только HTTP(S) URL без учётных данных и фрагментов.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new InvalidArgumentException('Допускаются только порты 80 и 443.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            // Require a conventional DNS name. Numeric alternate IPv4 spellings never reach curl.
            if (strlen($host) > 253 || !str_contains($host, '.') || preg_match('/^(?=.+[a-z])(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1) {
                throw new InvalidArgumentException('Некорректное доменное имя изображения.');
            }
            $addresses = $this->resolver->resolve($host, min(3.0, $deadline - microtime(true)));
        }
        if ($addresses === []) {
            throw new InvalidArgumentException('Домен изображения не имеет IP-адреса.');
        }
        foreach ($addresses as $address) {
            if (!$this->isPublicAddress($address)) {
                throw new InvalidArgumentException('Изображения доступны только с публичных IP-адресов.');
            }
        }

        return [$host, $port, $addresses[0]];
    }

    private function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $blocked = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
            '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64',
            '2001::/23', '2002::/16', '3fff::/20', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
        ];
        foreach ($blocked as $network) {
            if ($this->inNetwork($address, $network)) {
                return false;
            }
        }
        // Only the current globally allocated IPv6 unicast space is permitted.
        return !str_contains($address, ':') || $this->inNetwork($address, '2000::/3');
    }

    private function inNetwork(string $address, string $network): bool
    {
        [$prefix, $length] = explode('/', $network, 2);
        $addressBytes = inet_pton($address);
        $prefixBytes = inet_pton($prefix);
        if ($addressBytes === false || $prefixBytes === false || strlen($addressBytes) !== strlen($prefixBytes)) {
            return false;
        }
        $bits = (int) $length;
        $bytes = intdiv($bits, 8);
        if (substr($addressBytes, 0, $bytes) !== substr($prefixBytes, 0, $bytes)) {
            return false;
        }
        $remaining = $bits % 8;

        return $remaining === 0 || (ord($addressBytes[$bytes]) & (0xff << (8 - $remaining))) === (ord($prefixBytes[$bytes]) & (0xff << (8 - $remaining)));
    }
}
