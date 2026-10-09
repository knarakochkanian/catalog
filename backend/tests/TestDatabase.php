<?php

declare(strict_types=1);

namespace Tests;

use App\Database;
use Doctrine\ORM\Tools\SchemaTool;

final class TestDatabase
{
    public static function fresh(): Database
    {
        $url = getenv('TEST_DATABASE_URL');
        $db = new Database($url !== false && $url !== '' ? $url : 'sqlite:///:memory:');
        if ($url !== false && $url !== '') {
            $schema = 'test_'.bin2hex(random_bytes(8));
            $db->connection->executeStatement('CREATE SCHEMA '.$schema);
            $db->connection->executeStatement('SET search_path TO '.$schema);
            register_shutdown_function(static function () use ($db, $schema): void {
                $db->connection->executeStatement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
            });
        }
        $em = $db->entityManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        return $db;
    }
}
