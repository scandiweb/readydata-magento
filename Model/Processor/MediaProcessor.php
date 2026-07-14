<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

/**
 * PLACEHOLDER — product images / media gallery.
 *
 * Planned scope:
 *  - accept image URLs or pre-uploaded pub/media paths in the payload
 *  - download/copy files into pub/media/catalog/product with dispersion
 *  - upsert catalog_product_entity_media_gallery(+_value, +_value_to_entity)
 *  - set image/small_image/thumbnail EAV values via EavValueProcessor rows
 */
class MediaProcessor extends AbstractPlaceholderProcessor
{
    public function getSortOrder(): int
    {
        return 710;
    }
}
