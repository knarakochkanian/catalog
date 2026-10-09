<?php

declare(strict_types=1);

namespace App\Http;

use App\Bootstrap;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Application
{
    /** @return App<\Psr\Container\ContainerInterface|null> */
    public static function create(Bootstrap $container): App
    {
        $app = AppFactory::create();
        $sessions = new SessionManager($container->storage);
        $limits = new RateLimiter($container->db);
        $auth = new AuthController($container->users, $sessions, $limits);
        $products = new ProductController($container->products, $container->export, $container->storage);
        $imports = new ImportController($container->jobs, $container->publisher, $limits, $container->storage);
        $app->get('/api/health', static function (ServerRequestInterface $request, ResponseInterface $response) use ($container): ResponseInterface {
            $container->db->connection->fetchOne('SELECT 1');
            return Json::send($response, ['status' => 'ok']);
        });
        $app->get('/api/auth/session', [$auth,'session']);
        $app->post('/api/auth/login', [$auth,'login']);
        $app->post('/api/auth/logout', [$auth,'logout']);
        $app->get('/api/products', [$products,'list']);
        $app->get('/api/products/export', [$products,'export']);
        $app->get('/api/products/{id:[0-9]+}', [$products,'detail']);
        $app->get('/api/images/{filename}', [$products,'image']);
        $app->post('/api/imports', [$imports,'upload']);
        $app->get('/api/imports/{id:[a-f0-9]{32}}', [$imports,'status']);
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new SecurityMiddleware($sessions));
        $errors = $app->addErrorMiddleware(false, true, true);
        $errors->setDefaultErrorHandler(static function (ServerRequestInterface $request, \Throwable $error) use ($app): ResponseInterface {
            $status = 500;
            $code = 'internal_error';
            $message = 'Внутренняя ошибка сервера. Повторите позже.';
            if ($error instanceof ApiException) {
                $status = $error->status;
                $code = $error->errorCode;
                $message = $error->getMessage();
            } elseif ($error instanceof \InvalidArgumentException) {
                $status = 422;
                $code = 'validation';
                $message = $error->getMessage();
            } elseif ($error instanceof HttpException) {
                $status = $error->getCode();
                $code = 'http_error';
                $message = $status === 404 ? 'Страница API не найдена.' : 'Некорректный HTTP-запрос.';
            } else {
                error_log($error::class.': '.$error->getMessage());
                if ($request->getUri()->getPath() === '/api/health') {
                    $status = 503;
                    $message = 'База данных недоступна.';
                }
            }
            return Json::send($app->getResponseFactory()->createResponse(), ['error' => ['code' => $code,'message' => $message]], $status)->withHeader('Cache-Control', 'no-store')->withHeader('X-Content-Type-Options', 'nosniff');
        });
        return $app;
    }
}
