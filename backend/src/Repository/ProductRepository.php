<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database;
use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Import\ProductData;
use App\SpreadsheetLimits;

final class ProductRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Caller owns the transaction for product+report atomicity.
     * @param list<array{url:string,path:string}> $images
     */
    public function upsert(ProductData $data, array $images): bool
    {
        $em = $this->db->entityManager();
        $product = $em->getRepository(Product::class)->findOneBy(['externalCode' => $data->externalCode]);
        $created = $product === null;
        $keys = [];
        $existingId = $product === null ? 0 : $product->id;
        foreach ($this->db->connection->fetchFirstColumn('SELECT DISTINCT attribute_key FROM product_attributes WHERE product_id<>?', [$existingId]) as $key) {
            $keys[(string)$key] = true;
        }
        foreach ($data->attributes as $key => $value) {
            $keys[(string)$key] = true;
        }
        $count = (int)$this->db->connection->fetchOne('SELECT COUNT(*) FROM products') + ($created ? 1 : 0);
        $textBytes = $this->textBytesExcluding((int)$existingId) + strlen($data->externalCode) + strlen($data->name) + strlen($data->description) + 60 * $count + 8192;
        foreach ($data->attributes as $key => $value) {
            $textBytes += strlen((string)$key) + strlen($value);
        }
        SpreadsheetLimits::assertExportable($count, count($keys), $textBytes);
        $product ??= new Product();
        $product->externalCode = $data->externalCode;
        $product->name = $data->name;
        $product->description = $data->description;
        $product->price = $data->price;
        $product->purchasePrice = $data->purchasePrice;
        $product->discount = $data->discount;
        // Flush removals first to avoid unique key collisions when replacing the same attribute.
        foreach ($product->attributes as $attribute) {
            $em->remove($attribute);
        }
        foreach ($product->images as $image) {
            $em->remove($image);
        }
        $product->attributes->clear();
        $product->images->clear();
        if (!$created) {
            $em->flush();
        }
        foreach ($data->attributes as $key => $value) {
            $product->attributes->add(new ProductAttribute($product, (string)$key, $value));
        }
        foreach ($images as $image) {
            $product->images->add(new ProductImage($product, $image['url'], $image['path']));
        }
        $em->persist($product);
        $em->flush();
        $em->clear();
        return $created;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $product = $this->db->entityManager()->find(Product::class, $id);
        return $product ? $this->serialize($product) : null;
    }

    /** @return array{items:list<array<string,mixed>>,page:int,limit:int,total:int,total_pages:int} */
    public function list(int $page, int $limit, string $name, ?string $min, ?string $max): array
    {
        $qb = $this->db->entityManager()->createQueryBuilder()->select('p')->from(Product::class, 'p')->orderBy('p.id', 'ASC');
        $terms = preg_split('/[\s\p{Z}]+/u', mb_strtolower($name, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        if ($terms === false) {
            throw new \InvalidArgumentException('Некорректный текст для поиска.');
        }
        foreach (array_values(array_unique($terms)) as $index => $term) {
            // Match each word anywhere in the name; treat %, _ and the escape character literally.
            $parameter = 'name'.$index;
            $literal = strtr($term, ['!' => '!!','%' => '!%','_' => '!_']);
            $qb->andWhere("LOWER(p.name) LIKE :".$parameter." ESCAPE '!'")->setParameter($parameter, '%'.$literal.'%');
        }
        if ($min !== null) {
            $qb->andWhere('p.price >= :min')->setParameter('min', $min);
        }
        if ($max !== null) {
            $qb->andWhere('p.price <= :max')->setParameter('max', $max);
        }
        $count = clone $qb;
        $count->resetDQLPart('orderBy');
        $total = (int)$count->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        /** @var list<Product> $products */
        $products = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();
        // Only basic fields in the list: no lazy relation queries for every visible row.
        $items = array_map(fn (Product $p) => $this->serialize($p, false), $products);
        return ['items' => $items,'page' => $page,'limit' => $limit,'total' => $total,'total_pages' => (int)ceil($total / $limit)];
    }

    /** @return list<Product> */
    public function all(): array
    {
        $em = $this->db->entityManager();
        $total = (int)$em->createQuery('SELECT COUNT(p.id) FROM App\\Entity\\Product p')->getSingleScalarResult();
        SpreadsheetLimits::assertExportable($total, (int)$this->db->connection->fetchOne('SELECT COUNT(DISTINCT attribute_key) FROM product_attributes'), $this->textBytesExcluding(0) + 60 * $total + 8192);
        /** @var list<Product> $result */
        $result = $em->createQuery('SELECT DISTINCT p,a FROM App\\Entity\\Product p LEFT JOIN p.attributes a ORDER BY p.id')->getResult();
        // Initialize image collections in one separate query, avoiding attributes × images Cartesian growth.
        $em->createQuery('SELECT DISTINCT p,i FROM App\\Entity\\Product p LEFT JOIN p.images i ORDER BY p.id')->getResult();
        return $result;
    }

    public function delete(int $id): bool
    {
        $em = $this->db->entityManager();
        $p = $em->find(Product::class, $id);
        if (!$p) {
            return false;
        } $em->remove($p);
        $em->flush();
        return true;
    }

    /** @return list<string> */
    public function imagePathsForCode(string $code): array
    {
        /** @var list<string> $paths */
        $paths = $this->db->connection->fetchFirstColumn('SELECT i.path FROM product_images i JOIN products p ON p.id=i.product_id WHERE p.external_code=?', [$code]);
        return $paths;
    }

    public function imageExists(string $path): bool
    {
        return (int)$this->db->connection->fetchOne('SELECT COUNT(*) FROM product_images WHERE path=?', [$path]) > 0;
    }

    private function textBytesExcluding(int $id): int
    {
        $sqlite = $this->db->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform;
        $bytes = static fn (string $column) => $sqlite ? 'length(CAST('.$column.' AS BLOB))' : 'octet_length('.$column.')';
        $products = (int)$this->db->connection->fetchOne('SELECT COALESCE(SUM('.$bytes('external_code').'+'.$bytes('name').'+'.$bytes('description').'),0) FROM products WHERE id<>?', [$id]);
        $attributes = (int)$this->db->connection->fetchOne('SELECT COALESCE(SUM('.$bytes('attribute_key').'+'.$bytes('value').'),0) FROM product_attributes WHERE product_id<>?', [$id]);
        return $products + $attributes;
    }

    /** @return array<string,mixed> */
    private function serialize(Product $p, bool $relations = true): array
    {
        return ['id' => $p->id,'external_code' => $p->externalCode,'name' => $p->name,'description' => $p->description,'price' => bcadd($p->price, '0', 2),'purchase_price' => bcadd($p->purchasePrice, '0', 2),'discount' => bcadd($p->discount, '0', 4),
            'attributes' => $relations ? array_map(fn (ProductAttribute $a) => ['id' => $a->id,'key' => $a->key,'value' => $a->value], $p->attributes->toArray()) : [],
            'images' => $relations ? array_map(fn (ProductImage $i) => ['id' => $i->id,'url' => $i->url,'path' => '/api/images/'.$i->path], $p->images->toArray()) : []];
    }
}
