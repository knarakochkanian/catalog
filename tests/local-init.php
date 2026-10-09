<?php
declare(strict_types=1);
// Isolated fallback harness, not a production migration or queue replacement.
require dirname(__DIR__).'/backend/vendor/autoload.php';
$app=new App\Bootstrap();
if (!str_starts_with(App\Bootstrap::env('DATABASE_URL'),'sqlite:')) { throw new RuntimeException('local-init only accepts an isolated SQLite URL.'); }
$em=$app->db->entityManager();
(new Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
$app->users->seed(App\Bootstrap::env('ADMIN_USERNAME','admin'),App\Bootstrap::env('ADMIN_PASSWORD'));
echo "Изолированная тестовая БД создана.\n";
