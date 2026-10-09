<?php

declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class BoundedDnsResolver implements DnsResolver
{
    /** @return list<string> */
    public function resolve(string $host, float $timeoutSeconds): array
    {
        if ($timeoutSeconds <= 0 || !is_finite($timeoutSeconds)) {
            throw new InvalidArgumentException('Истекло время разрешения DNS.');
        }
        if (!function_exists('proc_open') || PHP_SAPI !== 'cli') {
            throw new RuntimeException('Разрешение DNS требует CLI worker с доступным proc_open.');
        }
        $deadline = microtime(true) + min(3.0, $timeoutSeconds);
        // A separate CLI process allows a hard deadline around the system DNS resolver.
        // The argument array bypasses the shell completely; the hostname is never executable text.
        $program = '$records = @dns_get_record($argv[1], DNS_A | DNS_AAAA); echo json_encode($records, JSON_THROW_ON_ERROR);';
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=16M', '-r', $program, $host], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if ($process === false) {
            throw new RuntimeException('Не удалось запустить DNS resolver.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        try {
            while (true) {
                $chunk = stream_get_contents($pipes[1]);
                if ($chunk === false) {
                    throw new RuntimeException('Не удалось прочитать результат DNS.');
                }
                $output .= $chunk;
                stream_get_contents($pipes[2]);
                if (strlen($output) > 65536) {
                    throw new InvalidArgumentException('Слишком большой ответ DNS.');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $remainder = stream_get_contents($pipes[1]);
                    if ($remainder !== false) {
                        $output .= $remainder;
                    }
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new InvalidArgumentException('DNS не ответил в отведённое время.');
                }
                usleep(1000);
            }
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        if (strlen($output) > 65536) {
            throw new InvalidArgumentException('Слишком большой ответ DNS.');
        }
        try {
            $records = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Не удалось разрешить домен изображения.', 0, $exception);
        }
        if (!is_array($records)) {
            throw new InvalidArgumentException('Не удалось разрешить домен изображения.');
        }
        $addresses = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException('Некорректный ответ DNS.');
            }
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && is_string($record[$key])) {
                    $addresses[] = $record[$key];
                }
            }
        }
        if ($addresses === []) {
            throw new InvalidArgumentException('Домен изображения не имеет IP-адреса.');
        }

        return array_values(array_unique($addresses));
    }
}
