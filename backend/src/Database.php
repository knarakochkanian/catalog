<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

final class Database
{
    public readonly Connection $connection;

    public function __construct(string $url)
    {
        $params = $url === 'sqlite:///:memory:' ? ['driver' => 'pdo_sqlite','memory' => true] : (new DsnParser(['postgresql' => 'pdo_pgsql','postgres' => 'pdo_pgsql','sqlite' => 'pdo_sqlite']))->parse($url);
        $this->connection = DriverManager::getConnection($params);
        if (($params['driver'] ?? '') === 'pdo_sqlite') {
            $native = $this->connection->getNativeConnection();
            if ($native instanceof \PDO) {
                $native->sqliteCreateFunction('lower', static fn (string $value) => mb_strtolower($value), 1);
            }
            $this->connection->executeStatement('PRAGMA foreign_keys=ON');
        }
    }

    public function entityManager(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/Entity'], true);
        $config->enableNativeLazyObjects(true);
        return new EntityManager($this->connection, $config);
    }
}
