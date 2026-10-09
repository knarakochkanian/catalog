<?php

declare(strict_types=1);

namespace App\Queue;

use App\Repository\JobRepository;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;

final class QueuePublisher
{
    public function __construct(private JobRepository $jobs, private string $dsn)
    {
    }
    public function publish(string $id): void
    {
        TransportFactory::create($this->dsn)->send(new Envelope(new ProcessImport($id), [new AmqpStamp('imports')]));
        $this->jobs->update($id, ['published_at' => time()]);
    }
}
