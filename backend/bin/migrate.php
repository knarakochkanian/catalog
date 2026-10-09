<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Bootstrap;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\MigratorConfiguration;

$app = new Bootstrap();
$factory = DependencyFactory::fromConnection(new ConfigurationArray(['migrations_paths' => ['App\\Migrations' => dirname(__DIR__).'/migrations'],'all_or_nothing' => true]), new ExistingConnection($app->db->connection));
$factory->getMetadataStorage()->ensureInitialized();
$version = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
$plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($version);
$config = new MigratorConfiguration();
$config->setAllOrNothing(true);
$factory->getMigrator()->migrate($plan, $config);
$app->users->seed(Bootstrap::env('ADMIN_USERNAME', 'admin'), Bootstrap::env('ADMIN_PASSWORD'));
fwrite(STDOUT, "Миграции выполнены, пользователь настроен.\n");
