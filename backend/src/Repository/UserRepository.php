<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database;
use App\Entity\User;

final class UserRepository
{
    public function __construct(private Database $db)
    {
    }
    public function authenticate(string $username, string $password): ?int
    {
        $user = $this->db->entityManager()->getRepository(User::class)->findOneBy(['username' => $username]);
        // Constant-cost hash verification even for unknown users.
        $hash = $user !== null ? $user->passwordHash : '$2y$12$wyqpOJQM5MdF88iJWIBVbu5lAtjnwPHwe5GH8nBiNy6pcHuJUar5m';
        return password_verify($password, $hash) && $user ? $user->id : null;
    }
    public function seed(string $username, string $password): void
    {
        if ($username === '' || strlen($password) < 12) {
            throw new \InvalidArgumentException('ADMIN_USERNAME обязателен, ADMIN_PASSWORD должен содержать минимум 12 символов.');
        }
        $em = $this->db->entityManager();
        $user = $em->getRepository(User::class)->findOneBy(['username' => $username]);
        if ($user) {
            return;
        }
        $user = new User();
        $user->username = $username;
        $user->passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $em->persist($user);
        $em->flush();
    }
}
