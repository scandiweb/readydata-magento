<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Processor;

use ReadyData\Import\Api\Data\ProductInterface;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\ResourceModel\AttributeOption;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\ProductEntity;
use ReadyData\Import\Model\UrlKeyGenerator;

/**
 * Writes all scalar EAV attribute values in bulk, grouped by backend type
 * (one upsert per catalog_product_entity_* table per batch).
 *
 * Publishes to the context data bag:
 *  - "url_keys": array<string sku, string> url_key written in this batch
 *    (provided or generated); consumed by UrlRewriteProcessor.
 */
class EavValueProcessor implements ProcessorInterface
{
    public const CONTEXT_URL_KEYS = 'url_keys';

    private const SCOPE_GLOBAL = 1;

    public function __construct(
        private readonly AttributeMetadataCache $attributeMetadataCache,
        private readonly AttributeOption $attributeOption,
        private readonly EavValue $eavValue,
        private readonly ProductEntity $productEntity,
        private readonly UrlKeyGenerator $urlKeyGenerator
    ) {
    }

    public function process(BatchContext $context): void
    {
        $linkIds = $context->get(EntityProcessor::CONTEXT_LINK_IDS, []);
        $linkField = $this->productEntity->getLinkField();
        $urlKeys = [];
        $rowsByType = [];

        foreach ($context->getValidProducts() as $sku => $product) {
            $linkId = $linkIds[$sku] ?? null;
            if ($linkId === null) {
                $context->fail($sku, 'Missing entity link ID; entity processor did not resolve this product.');
                continue;
            }

            $values = $this->collectValues($context, $product);
            if (isset($values['url_key'])) {
                $urlKeys[$sku] = (string)$values['url_key'];
            }

            foreach ($values as $code => $value) {
                $meta = $this->attributeMetadataCache->get((string)$code);
                if ($meta === null) {
                    $context->addMessage($sku, sprintf('Unknown attribute "%s" skipped.', $code));
                    continue;
                }
                if ($meta['backend_type'] === 'static') {
                    continue;
                }

                $value = $this->prepareValue($meta, (string)$value);
                if ($value === null) {
                    $context->addMessage(
                        $sku,
                        sprintf('Value "%s" for attribute "%s" could not be resolved; skipped.', $values[$code], $code)
                    );
                    continue;
                }

                $storeId = $meta['is_global'] === self::SCOPE_GLOBAL ? 0 : $context->getStoreId();
                $rowsByType[$meta['backend_type']][] = [
                    $linkField => $linkId,
                    'attribute_id' => $meta['attribute_id'],
                    'store_id' => $storeId,
                    'value' => $value,
                ];
                // New products always need a default-scope fallback row.
                if ($storeId !== 0 && !$context->isExisting($sku)) {
                    $rowsByType[$meta['backend_type']][] = [
                        $linkField => $linkId,
                        'attribute_id' => $meta['attribute_id'],
                        'store_id' => 0,
                        'value' => $value,
                    ];
                }
            }
        }

        foreach ($rowsByType as $backendType => $rows) {
            $this->eavValue->upsert((string)$backendType, $rows);
        }

        $context->set(self::CONTEXT_URL_KEYS, $urlKeys);
    }

    /**
     * Flatten first-class fields + custom attributes into code => raw value.
     *
     * @return array<string, string|int|float>
     */
    private function collectValues(BatchContext $context, ProductInterface $product): array
    {
        $values = array_filter(
            [
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'status' => $product->getStatus(),
                'visibility' => $product->getVisibility(),
                'weight' => $product->getWeight(),
                'url_key' => $product->getUrlKey(),
            ],
            static fn ($value): bool => $value !== null
        );

        foreach ($product->getCustomAttributes() ?? [] as $customAttribute) {
            if ($customAttribute->getValue() !== null) {
                $values[$customAttribute->getAttributeCode()] = $customAttribute->getValue();
            }
        }

        // Generate a url_key for new products that have none.
        if (!isset($values['url_key'])
            && !$context->isExisting($product->getSku())
            && $product->getName() !== null
        ) {
            $values['url_key'] = $this->urlKeyGenerator->generate($product->getName());
        }

        return $values;
    }

    /**
     * Resolve option labels and cast to the backend type.
     * Returns null when the value cannot be resolved (e.g. unknown option).
     */
    private function prepareValue(array $meta, string $value): string|int|float|null
    {
        if (!in_array($meta['attribute_code'], ['status', 'visibility'], true)) {
            if ($meta['frontend_input'] === 'select') {
                return $this->attributeOption->getOptionId($meta['attribute_id'], $value);
            }
            if ($meta['frontend_input'] === 'multiselect') {
                $optionIds = [];
                foreach (array_filter(array_map('trim', explode(',', $value))) as $label) {
                    $optionId = $this->attributeOption->getOptionId($meta['attribute_id'], $label);
                    if ($optionId === null) {
                        return null;
                    }
                    $optionIds[] = $optionId;
                }

                return implode(',', $optionIds);
            }
        }

        return match ($meta['backend_type']) {
            'int' => match (mb_strtolower($value)) {
                'true', 'yes' => 1,
                'false', 'no' => 0,
                default => (int)$value,
            },
            'decimal' => (float)$value,
            default => $value,
        };
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 300;
    }
}
