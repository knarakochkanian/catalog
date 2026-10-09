<?php

declare(strict_types=1);

namespace App\Http;

use App\Bootstrap;

final class SessionManager
{
    public function __construct(private string $storage)
    {
    }
    public function open(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_save_path($this->storage.'/sessions');
        session_start(['use_strict_mode' => 1,'use_only_cookies' => 1,'cookie_httponly' => true,'cookie_samesite' => 'Lax','cookie_secure' => Bootstrap::env('SESSION_SECURE', '0') === '1','cookie_path' => '/','gc_maxlifetime' => 3600]);
        if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > 1800) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
    }
    /** @return array{authenticated:bool,csrf_token:string,username?:string} */
    public function snapshot(): array
    {
        $result = ['authenticated' => isset($_SESSION['user_id']),'csrf_token' => (string)$_SESSION['csrf_token']];
        if (isset($_SESSION['username'])) {
            $result['username'] = (string)$_SESSION['username'];
        }
        return $result;
    }
    public function login(int $id, string $username): void
    {
        $this->open();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['username'] = $username;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    public function logout(): void
    {
        $this->open();
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
