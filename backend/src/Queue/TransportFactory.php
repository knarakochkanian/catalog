<?php

declare(strict_types=1);

namespace App\Queue;

use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class TransportFactory
{
    public static function create(string $dsn, bool $failed = false): TransportInterface
    {
        $name = $failed ? 'imports_failed' : 'imports';
        return (new AmqpTransportFactory())->createTransport($dsn, [
            'exchange' => ['name' => $name,'type' => 'direct','default_publish_routing_key' => $name],
            'queues' => [$name => ['binding_keys' => [$name]]],
            'delay' => ['exchange_name' => $name.'_delays'],
            'connect_timeout' => 3,'read_timeout' => 5,'write_timeout' => 5,
        ], new JobSerializer());
    }
}
