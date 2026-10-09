<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Bootstrap;

$app = new Bootstrap();
$running = true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$running): void {
    $running = false;
});
pcntl_signal(SIGINT, static function () use (&$running): void {
    $running = false;
});
while ($running) {
    foreach ($app->jobs->pending() as $id) {
        try {
            $app->publisher->publish($id);
        } catch (Throwable $e) {
            error_log('Outbox publication failed: '.$e->getMessage());
            break;
        }
    }
    sleep(3);
}
