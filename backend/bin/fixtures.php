<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Bootstrap;
use App\Import\ProductData;

$app = new Bootstrap();
$path = bin2hex(random_bytes(16)).'.png';
$image = imagecreatetruecolor(400, 400);
if ($image === false) {
    throw new RuntimeException('Cannot create fixture image.');
}
imagefill($image, 0, 0, imagecolorallocate($image, 223, 218, 251));
imagepng($image, $app->storage.'/images/'.$path);
imagedestroy($image);
$old = $app->products->imagePathsForCode('fixture-001');
$app->db->connection->beginTransaction();
try {
    $app->products->upsert(new ProductData('fixture-001', 'Демонстрационный товар', 'Пример для проверки связей БД.', '120.00', '80.00', '33.3333', ['Цвет' => 'Сиреневый','Размер' => 'M'], []), [['url' => 'https://httpbin.org/image/png','path' => $path]]);
    $app->db->connection->commit();
} catch (Throwable $e) {
    $app->db->connection->rollBack();
    unlink($app->storage.'/images/'.$path);
    throw $e;
}
foreach ($old as $name) {
    if (!$app->products->imageExists($name) && is_file($app->storage.'/images/'.$name)) {
        unlink($app->storage.'/images/'.$name);
    }
}
fwrite(STDOUT, "Созданы fixtures: товар, два атрибута, изображение.\n");
