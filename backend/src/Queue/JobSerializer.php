<?php

declare(strict_types=1);

namespace App\Queue;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class JobSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            $payload = json_decode($encodedEnvelope['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new MessageDecodingFailedException('Некорректное JSON сообщение очереди.', 0, $e);
        }
        if (!is_array($payload) || !is_string($payload['job_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $payload['job_id']) || !is_int($payload['retry'] ?? null) || $payload['retry'] < 0 || $payload['retry'] > 10) {
            throw new MessageDecodingFailedException('Некорректные поля сообщения очереди.');
        }
        return new Envelope(new ProcessImport($payload['job_id']), [new RedeliveryStamp($payload['retry'])]);
    }
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if (!$message instanceof ProcessImport) {
            throw new \InvalidArgumentException('Неподдерживаемое сообщение.');
        }
        return ['body' => json_encode(['job_id' => $message->id,'retry' => RedeliveryStamp::getRetryCountFromEnvelope($envelope)], JSON_THROW_ON_ERROR),'headers' => ['Content-Type' => 'application/json']];
    }
}
