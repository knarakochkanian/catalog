<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\UserRepository;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

final class AuthController
{
    public function __construct(private UserRepository $users, private SessionManager $sessions, private RateLimiter $limits)
    {
    }
    public function session(Request $request, Response $response): Response
    {
        return Json::send($response, $request->getAttribute('session'));
    }
    public function login(Request $request, Response $response): Response
    {
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        if (!$this->limits->allow('login:'.$ip, 10, 300)) {
            throw new ApiException(429, 'rate_limit', 'Слишком много попыток входа. Повторите через 5 минут.');
        }
        $data = $request->getParsedBody();
        if (!is_array($data) || !is_string($data['username'] ?? null) || !is_string($data['password'] ?? null) || strlen($data['username']) > 100 || strlen($data['password']) > 1024) {
            throw new ApiException(422, 'validation', 'Введите логин и пароль.');
        }
        $id = $this->users->authenticate($data['username'], $data['password']);
        if ($id === null) {
            throw new ApiException(401, 'invalid_credentials', 'Неверный логин или пароль.');
        }
        $this->sessions->login($id, $data['username']);
        $snapshot = $this->sessions->snapshot();
        session_write_close();
        return Json::send($response, $snapshot);
    }
    public function logout(Request $request, Response $response): Response
    {
        $this->sessions->logout();
        session_write_close();
        return $response->withStatus(204);
    }
}
