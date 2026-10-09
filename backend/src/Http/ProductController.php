<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\ProductRepository;
use App\Service\ExportService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Stream;

final class ProductController
{
    public function __construct(private ProductRepository $products, private ExportService $export, private string $storage)
    {
    }
    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = $this->integer($params['page'] ?? '1', 1, 100000, 'page');
        $limit = $this->integer($params['limit'] ?? '20', 1, 100, 'limit');
        $name = $params['name'] ?? '';
        if (!is_string($name) || mb_strlen($name) > 255) {
            throw new ApiException(422, 'validation', 'Название поиска слишком длинное.');
        }
        $min = $this->price($params['min_price'] ?? null);
        $max = $this->price($params['max_price'] ?? null);
        if ($min !== null && $max !== null && bccomp($min, $max, 2) > 0) {
            throw new ApiException(422, 'validation', 'Минимальная цена не должна превышать максимальную.');
        }
        return Json::send($response, $this->products->list($page, $limit, trim($name), $min, $max));
    }
    /** @param array<string,string> $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        $product = $this->products->get($this->integer($args['id'], 1, PHP_INT_MAX, 'id'));
        if (!$product) {
            throw new ApiException(404, 'not_found', 'Товар не найден.');
        }
        return Json::send($response, $product);
    }
    public function export(Request $request, Response $response): Response
    {
        $file = $this->export->export();
        $handle = fopen($file, 'rb');
        unlink($file);
        if (!$handle) {
            throw new \RuntimeException('Не удалось прочитать экспорт.');
        }
        return $response->withBody(new Stream($handle))->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->withHeader('Content-Disposition', 'attachment; filename="products.xlsx"');
    }
    /** @param array<string,string> $args */
    public function image(Request $request, Response $response, array $args): Response
    {
        $name = $args['filename'];
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/D', $name) || !$this->products->imageExists($name)) {
            throw new ApiException(404, 'not_found', 'Изображение не найдено.');
        }
        $path = $this->storage.'/images/'.$name;
        if (!is_file($path) || is_link($path)) {
            throw new ApiException(404, 'not_found', 'Изображение недоступно.');
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Не удалось прочитать изображение.');
        }
        return $response->withBody(new Stream($handle))->withHeader('Content-Type', (string)(new \finfo(FILEINFO_MIME_TYPE))->file($path))->withHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }
    private function integer(mixed $value, int $min, int $max, string $name): int
    {
        if (!is_scalar($value) || !preg_match('/^[0-9]+$/D', (string)$value)) {
            throw new ApiException(422, 'validation', 'Некорректный параметр '.$name.'.');
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min,'max_range' => $max]]);
        if ($number === false) {
            throw new ApiException(422, 'validation', 'Параметр '.$name.' вне допустимого диапазона.');
        }
        return $number;
    }
    private function price(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^[0-9]{1,12}([.,][0-9]{1,2})?$/D', $value)) {
            throw new ApiException(422, 'validation', 'Некорректная цена в фильтре.');
        }
        return bcadd(str_replace(',', '.', $value), '0', 2);
    }
}
