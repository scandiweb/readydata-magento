<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\ResourceModel;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Read-only category tree lookups for path resolution. Names are always
 * matched against the store-0 (admin) values — the same names the admin
 * category tree shows.
 */
class Category
{
    private const ENTITY_TABLE = 'catalog_category_entity';

    private ?string $linkField = null;
    private ?int $nameAttributeId = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * Level-1 roots (children of the tree root): store-0 name => entity_id.
     * On duplicate names the lowest entity_id wins, deterministically.
     *
     * @return array<string, int>
     */
    public function getRootCategories(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $this->joinName(
            $connection->select()
                ->from(
                    ['e' => $this->resourceConnection->getTableName(self::ENTITY_TABLE)],
                    ['entity_id']
                )
                ->where('e.level = ?', 1)
                ->order('e.entity_id ' . \Magento\Framework\DB\Select::SQL_ASC)
        );

        $roots = [];
        foreach ($connection->fetchAll($select) as $row) {
            $roots[(string)$row['name']] ??= (int)$row['entity_id'];
        }

        return $roots;
    }

    /**
     * Direct children of the given parents with their store-0 names.
     * On duplicate sibling names the lowest entity_id wins.
     *
     * @param int[] $parentIds
     * @return array<int, array<string, int>> parent_id => [name => entity_id]
     */
    public function getChildrenByParentIds(array $parentIds): array
    {
        if (!$parentIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $this->joinName(
            $connection->select()
                ->from(
                    ['e' => $this->resourceConnection->getTableName(self::ENTITY_TABLE)],
                    ['entity_id', 'parent_id']
                )
                ->where('e.parent_id IN (?)', $parentIds)
                ->order('e.entity_id ' . \Magento\Framework\DB\Select::SQL_ASC)
        );

        $children = [];
        foreach ($connection->fetchAll($select) as $row) {
            $children[(int)$row['parent_id']][(string)$row['name']] ??= (int)$row['entity_id'];
        }

        return $children;
    }

    /**
     * Existence and tree-position check, used to validate numeric payload
     * references and to re-verify categories created earlier in the request.
     *
     * @param int[] $categoryIds
     * @return array<int, array{entity_id: int, parent_id: int, level: int, path: string}>
     */
    public function getExistingByIds(array $categoryIds): array
    {
        if (!$categoryIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::ENTITY_TABLE),
                ['entity_id', 'parent_id', 'level', 'path']
            )
            ->where('entity_id IN (?)', $categoryIds);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['entity_id']] = [
                'entity_id' => (int)$row['entity_id'],
                'parent_id' => (int)$row['parent_id'],
                'level' => (int)$row['level'],
                'path' => (string)$row['path'],
            ];
        }

        return $result;
    }

    /**
     * Required category attributes with int backend and no default value —
     * typically third-party "required select" (yes/no) attributes that would
     * otherwise block programmatic category creation with an "attribute
     * value is empty" validation error.
     *
     * @return string[] attribute codes
     */
    public function getRequiredIntAttributesWithoutDefault(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['a' => $this->resourceConnection->getTableName('eav_attribute')],
                ['attribute_code']
            )
            ->join(
                ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                't.entity_type_id = a.entity_type_id',
                []
            )
            ->where('t.entity_type_code = ?', CategoryModel::ENTITY)
            ->where('a.is_required = 1')
            ->where('a.backend_type = ?', 'int')
            ->where("a.default_value IS NULL OR a.default_value = ''");

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * Join the store-0 name value onto a catalog_category_entity select
     * aliased "e".
     */
    private function joinName(\Magento\Framework\DB\Select $select): \Magento\Framework\DB\Select
    {
        $linkField = $this->getLinkField();

        return $select->join(
            ['v' => $this->resourceConnection->getTableName(self::ENTITY_TABLE . '_varchar')],
            sprintf('v.%1$s = e.%1$s', $linkField)
            . ' AND v.attribute_id = ' . $this->getNameAttributeId()
            . ' AND v.store_id = 0',
            ['name' => 'value']
        );
    }

    private function getLinkField(): string
    {
        if ($this->linkField === null) {
            $this->linkField = $this->metadataPool
                ->getMetadata(CategoryInterface::class)
                ->getLinkField();
        }

        return $this->linkField;
    }

    private function getNameAttributeId(): int
    {
        if ($this->nameAttributeId === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(
                    ['a' => $this->resourceConnection->getTableName('eav_attribute')],
                    ['attribute_id']
                )
                ->join(
                    ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('t.entity_type_code = ?', CategoryModel::ENTITY)
                ->where('a.attribute_code = ?', 'name');

            $this->nameAttributeId = (int)$connection->fetchOne($select);
        }

        return $this->nameAttributeId;
    }
}
