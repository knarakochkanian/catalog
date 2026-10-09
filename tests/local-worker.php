<?php
declare(strict_types=1);
// Explicit native preview helper. Production always consumes Symfony Messenger/RabbitMQ.
require dirname(__DIR__).'/backend/vendor/autoload.php';
if (getenv('E2E_LOCAL_WORKER')!=='1' || !str_starts_with(App\Bootstrap::env('DATABASE_URL'),'sqlite:')) { throw new RuntimeException('Native preview requires explicit isolated SQLite test mode.'); }
$app=new App\Bootstrap();$running=true;$attempts=[];
pcntl_async_signals(true);pcntl_signal(SIGTERM,static function() use(&$running):void {$running=false;});pcntl_signal(SIGINT,static function() use(&$running):void {$running=false;});
while ($running) {
    foreach ($app->jobs->pending() as $id) {
        try {$app->processor->process($id);unset($attempts[$id]);}
        catch (Throwable $e) {
            error_log('Native preview worker: '.$e->getMessage());$attempts[$id]=($attempts[$id]??0)+1;
            if ($attempts[$id]>=3) {$app->jobs->update($id,['status'=>'failed','message'=>'Ошибка тестового фонового обработчика. Подробности в журнале.']);}
        }
    }
    sleep(1);
}
