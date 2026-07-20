<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Cache;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Request-scoped cache of store/website code => ID maps, read straight
 * from the DB to avoid store model overhead.
 */
class StoreWebsiteMap
{
    private ?array $storeIdByCode = null;
    private ?array $websiteIdByCode = null;
    private ?array $storeIdsByWebsiteId = null;
    private ?array $websiteStoreIds = null;
    private ?int $defaultWebsiteId = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Resolve a store view code to its ID; null/"admin" means global scope (0).
     *
     * @throws LocalizedException on unknown code
     */
    public function resolveStoreId(?string $storeViewCode): int
    {
        if ($storeViewCode === null || $storeViewCode === '' || $storeViewCode === 'admin') {
            return 0;
        }

        $storeId = $this->getStoreMap()[$storeViewCode] ?? null;
        if ($storeId === null) {
            throw new LocalizedException(__('Unknown store view code "%1".', $storeViewCode));
        }

        return $storeId;
    }

    public function getWebsiteId(string $websiteCode): ?int
    {
        return $this->getWebsiteMap()[$websiteCode] ?? null;
    }

    /**
     * @return array<string, int> website code => ID (admin website excluded)
     */
    public function getWebsiteMap(): array
    {
        if ($this->websiteIdByCode === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store_website'), ['code', 'website_id'])
                ->where('website_id > 0');
            $this->websiteIdByCode = array_map('intval', $connection->fetchPairs($select));
        }

        return $this->websiteIdByCode;
    }

    public function getDefaultWebsiteId(): int
    {
        if ($this->defaultWebsiteId === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store_website'), 'website_id')
                ->where('is_default = 1');
            $this->defaultWebsiteId = (int)$connection->fetchOne($select);
        }

        return $this->defaultWebsiteId;
    }

    /**
     * Active store view IDs belonging to the given websites (for URL rewrites).
     *
     * @param int[] $websiteIds
     * @return int[]
     */
    public function getStoreIdsForWebsites(array $websiteIds): array
    {
        if ($this->storeIdsByWebsiteId === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store'), ['website_id', 'store_id'])
                ->where('store_id > 0')
                ->where('is_active = 1');
            $this->storeIdsByWebsiteId = [];
            foreach ($connection->fetchAll($select) as $row) {
                $this->storeIdsByWebsiteId[(int)$row['website_id']][] = (int)$row['store_id'];
            }
        }

        $storeIds = [];
        foreach ($websiteIds as $websiteId) {
            $storeIds[] = $this->storeIdsByWebsiteId[$websiteId] ?? [];
        }

        return array_values(array_unique(array_merge(...$storeIds ?: [[]])));
    }

    /**
     * All store view IDs of the website containing the given store view,
     * including the view itself and inactive views (website-scoped values
     * must not go stale on views activated later).
     *
     * @return int[]
     */
    public function getWebsiteStoreIds(int $storeId): array
    {
        if ($this->websiteStoreIds === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store'), ['website_id', 'store_id'])
                ->where('store_id > 0');
            $storeIdsByWebsiteId = [];
            $websiteIdByStoreId = [];
            foreach ($connection->fetchAll($select) as $row) {
                $storeIdsByWebsiteId[(int)$row['website_id']][] = (int)$row['store_id'];
                $websiteIdByStoreId[(int)$row['store_id']] = (int)$row['website_id'];
            }
            $this->websiteStoreIds = [];
            foreach ($websiteIdByStoreId as $id => $websiteId) {
                $this->websiteStoreIds[$id] = $storeIdsByWebsiteId[$websiteId];
            }
        }

        return $this->websiteStoreIds[$storeId] ?? [$storeId];
    }

    /**
     * @return array<string, int> store view code => ID
     */
    private function getStoreMap(): array
    {
        if ($this->storeIdByCode === null) {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('store'), ['code', 'store_id'])
                ->where('store_id > 0');
            $this->storeIdByCode = array_map('intval', $connection->fetchPairs($select));
        }

        return $this->storeIdByCode;
    }
}
