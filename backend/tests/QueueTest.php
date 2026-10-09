<?php

declare(strict_types=1);

namespace Tests;

use App\Queue\JobSerializer;
use App\Queue\ProcessImport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

final class QueueTest extends TestCase
{
    public function testJsonRoundtripPreservesJobAndRetryCount(): void
    {
        $serializer = new JobSerializer();
        $message = new ProcessImport(str_repeat('a', 32));
        $encoded = $serializer->encode(new Envelope($message, [new RedeliveryStamp(2)]));
        $decoded = $serializer->decode($encoded);
        self::assertSame($message->id, $decoded->getMessage()->id);
        self::assertSame(2, $decoded->last(RedeliveryStamp::class)->getRetryCount());
        self::assertStringNotContainsString('O:', $encoded['body']);
    }
    public function testMalformedQueuePayloadCannotInstantiateAnObject(): void
    {
        $this->expectException(MessageDecodingFailedException::class);
        (new JobSerializer())->decode(['body' => 'O:8:"stdClass":0:{}','headers' => []]);
    }
}
