<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

/**
 * PLACEHOLDER — related / up-sell / cross-sell links.
 *
 * Planned scope:
 *  - accept linked SKU lists per link type in the payload
 *  - resolve target SKUs (including SKUs created earlier in this import)
 *  - upsert catalog_product_link (+ catalog_product_link_attribute_int for position)
 */
class LinkProcessor extends AbstractPlaceholderProcessor
{
    public function getSortOrder(): int
    {
        return 720;
    }
}
