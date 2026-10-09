<?php

declare(strict_types=1);

namespace App\Http;

use App\Bootstrap;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionManager $sessions)
    {
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/api/health') {
            return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
        }
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== Bootstrap::env('APP_ORIGIN', 'http://localhost:8080')) {
            throw new ApiException(403, 'origin', 'Запрос с другого сайта запрещён.');
        }
        $this->sessions->open();
        $snapshot = $this->sessions->snapshot();
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        session_write_close();
        if (!in_array($request->getMethod(), ['GET','HEAD','OPTIONS'], true) && !hash_equals($snapshot['csrf_token'], $request->getHeaderLine('X-CSRF-Token'))) {
            throw new ApiException(403, 'csrf', 'Сессия устарела. Обновите страницу и повторите действие.');
        }
        if (!in_array($path, ['/api/auth/session','/api/auth/login'], true) && $userId === null) {
            throw new ApiException(401, 'unauthenticated', 'Войдите, чтобы продолжить.');
        }
        return $handler->handle($request->withAttribute('user_id', $userId)->withAttribute('session', $snapshot))->withHeader('Cache-Control', 'no-store')->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
