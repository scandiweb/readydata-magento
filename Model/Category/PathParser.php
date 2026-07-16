<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Category;

/**
 * Normalizes and classifies one entry of a product's "categories" payload
 * field: either a numeric category ID or a full category path from the
 * root category name ("Default Category/Men/Shirts").
 *
 * "/" is the separator with no escape syntax; categories whose name
 * contains "/" must be referenced by numeric ID.
 */
final class PathParser
{
    public const TYPE_ID = 'id';
    public const TYPE_PATH = 'path';
    public const SEPARATOR = '/';

    /**
     * @return array{type: string, id?: int, segments?: string[]}|null
     *         null when the entry is empty or unusable after normalization
     */
    public function parse(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        if (ctype_digit($reference)) {
            return ['type' => self::TYPE_ID, 'id' => (int)$reference];
        }

        $segments = [];
        foreach (explode(self::SEPARATOR, $reference) as $segment) {
            $segment = trim($segment);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        if (!$segments) {
            return null;
        }

        return ['type' => self::TYPE_PATH, 'segments' => $segments];
    }
}
