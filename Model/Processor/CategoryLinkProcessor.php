<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

/**
 * PLACEHOLDER — category assignments.
 *
 * Planned scope:
 *  - accept category paths ("Default Category/Men/Shirts") or IDs in the payload
 *  - bulk-resolve/create category trees, cache path => ID per request
 *  - upsert catalog_category_product (entity_id, category_id, position)
 *  - feed catalog_category_product indexer through InvalidationHandler
 */
class CategoryLinkProcessor extends AbstractPlaceholderProcessor
{
    public function getSortOrder(): int
    {
        return 700;
    }
}
