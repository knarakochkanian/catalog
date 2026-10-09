<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
foreach ([Slim\App::class,Doctrine\ORM\EntityManager::class,Symfony\Component\Messenger\Worker::class,Symfony\Component\EventDispatcher\EventDispatcher::class,Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory::class,PhpOffice\PhpSpreadsheet\Reader\Xlsx::class] as $class) {
    if (!class_exists($class)) {
        throw new RuntimeException('Missing production dependency: '.$class);
    }
}
echo "Production dependencies available.\n";
