<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

/**
 * PLACEHOLDER — tier / group prices.
 *
 * Planned scope:
 *  - accept tier price rows (customer group, qty, price/percentage, website)
 *  - replace catalog_product_entity_tier_price rows per product in bulk
 */
class TierPriceProcessor extends AbstractPlaceholderProcessor
{
    public function getSortOrder(): int
    {
        return 740;
    }
}
