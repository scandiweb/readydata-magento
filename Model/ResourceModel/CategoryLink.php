<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Product-to-category assignments (catalog_category_product). The table is
 * global (no store dimension) and references catalog_product_entity.entity_id
 * on both CE and EE.
 */
class CategoryLink
{
    private const TABLE = 'catalog_category_product';
    private const CHUNK = 1000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Current category assignments of the given products.
     *
     * @param int[] $productIds entity IDs
     * @return array<int, int[]> product_id => category IDs
     */
    public function getAssignments(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::TABLE),
                ['product_id', 'category_id']
            )
            ->where('product_id IN (?)', $productIds);

        $assignments = [];
        foreach ($connection->fetchAll($select) as $row) {
            $assignments[(int)$row['product_id']][] = (int)$row['category_id'];
        }

        return $assignments;
    }

    /**
     * No-op upsert: new pairs are inserted with their given position,
     * existing pairs — including admin-set positions — are left untouched.
     *
     * @param array<int, array{category_id: int, product_id: int, position: int}> $rows
     */
    public function assign(array $rows): void
    {
        if (!$rows) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $connection->insertOnDuplicate($table, $chunk, ['product_id']);
        }
    }

    /**
     * @param array<int, array{category_id: int, product_id: int}> $pairs
     */
    public function unassign(array $pairs): void
    {
        if (!$pairs) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        foreach (array_chunk($pairs, self::CHUNK) as $chunk) {
            $tuples = [];
            foreach ($chunk as $pair) {
                $tuples[] = $connection->quoteInto(
                    '(?)',
                    [(int)$pair['category_id'], (int)$pair['product_id']]
                );
            }
            $connection->delete(
                $table,
                '(category_id, product_id) IN (' . implode(', ', $tuples) . ')'
            );
        }
    }
}
