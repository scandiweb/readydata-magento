<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

/**
 * PLACEHOLDER — configurable product structure.
 *
 * Planned scope:
 *  - accept super attribute codes + child SKUs on configurable products
 *  - upsert catalog_product_super_attribute(+_label) and
 *    catalog_product_super_link / catalog_product_relation
 *  - validate children share the attribute set and have the super attribute values
 */
class ConfigurableProcessor extends AbstractPlaceholderProcessor
{
    public function getSortOrder(): int
    {
        return 730;
    }
}
