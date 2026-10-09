<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Bootstrap;
use App\Queue\ProcessImport;
use App\Queue\TransportFactory;
use App\Queue\MapContainer;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

$app = new Bootstrap();
$dsn = Bootstrap::env('MESSENGER_TRANSPORT_DSN');
$transport = TransportFactory::create($dsn);
$failure = TransportFactory::create($dsn, true);
$bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([ProcessImport::class => [static fn (ProcessImport $job) => $app->processor->process($job->id)]]))]);
$events = new EventDispatcher();
// Route retries to the same queue and permanent failures to an isolated durable queue.
$failedSender = new class ($failure) implements SenderInterface {
    public function __construct(private SenderInterface $inner)
    {
    }
    public function send(Envelope $envelope): Envelope
    {
        return $this->inner->send($envelope->withoutAll(AmqpStamp::class)->with(new AmqpStamp('imports_failed')));
    }
};
$events->addSubscriber(new SendFailedMessageForRetryListener(new MapContainer(['async' => $transport]), new MapContainer(['async' => new MultiplierRetryStrategy(3, 1000, 2, 10000)])));
$events->addSubscriber(new SendFailedMessageToFailureTransportListener(new MapContainer(['async' => $failedSender])));
$events->addSubscriber(new StopWorkerOnMemoryLimitListener(384 * 1024 * 1024));
$events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use ($app): void {
    error_log('Import worker failure: '.$event->getThrowable()->getMessage());
    $message = $event->getEnvelope()->getMessage();
    if (!$event->willRetry() && $message instanceof ProcessImport) {
        $app->jobs->update($message->id, ['status' => 'failed','message' => 'Ошибка обработки задания после повторных попыток. Подробности в журнале сервера.']);
    }
}, -50);
$worker = new Worker(['async' => $transport], $bus, $events);
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static fn () => $worker->stop());
pcntl_signal(SIGINT, static fn () => $worker->stop());
$worker->run(['sleep' => 500000]);
