<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\AttributeMetadataCache;
use ReadyData\Import\Model\Cache\StoreWebsiteMap;
use ReadyData\Import\Model\Config;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Processor\EavValueProcessor;
use ReadyData\Import\Model\Processor\UrlRewriteProcessor;
use ReadyData\Import\Model\Processor\WebsiteProcessor;
use ReadyData\Import\Model\ResourceModel\EavValue;
use ReadyData\Import\Model\ResourceModel\UrlRewrite;

class UrlRewriteProcessorTest extends TestCase
{
    private UrlRewrite&MockObject $urlRewriteResource;
    private StoreWebsiteMap&MockObject $storeWebsiteMap;
    private Config&MockObject $config;
    private UrlRewriteProcessor $processor;

    /**
     * @var array{entityIds: int[], storeIds: int[], rows: array<int, array>}|null
     */
    private ?array $replaced = null;

    protected function setUp(): void
    {
        $this->urlRewriteResource = $this->createMock(UrlRewrite::class);
        $this->urlRewriteResource->method('getProductUrlSuffix')->willReturn('.html');
        $this->urlRewriteResource->method('replaceProductRewrites')->willReturnCallback(
            function (array $entityIds, array $storeIds, array $rows): void {
                $this->replaced = ['entityIds' => $entityIds, 'storeIds' => $storeIds, 'rows' => $rows];
            }
        );

        $this->storeWebsiteMap = $this->createMock(StoreWebsiteMap::class);
        $this->storeWebsiteMap->method('getStoreIdsForWebsites')->willReturn([1]);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getUrlRewriteConflictStrategy')->willReturn(Config::URL_CONFLICT_APPEND);

        $this->processor = new UrlRewriteProcessor(
            $this->urlRewriteResource,
            $this->createMock(EavValue::class),
            $this->createMock(AttributeMetadataCache::class),
            $this->storeWebsiteMap,
            $this->config
        );
    }

    public function testSameBatchDuplicateUrlKeyIsDisambiguatedInsteadOfCollapsing(): void
    {
        // No pre-existing DB rewrites: the collision is purely within the batch.
        $this->urlRewriteResource->method('findConflicts')->willReturn([]);

        $context = $this->createContext([
            'SKU-A' => 8454,
            'SKU-B' => 8816,
        ], urlKey: 'shared-key');

        $this->processor->process($context);

        self::assertNotNull($this->replaced);
        $pathsByEntity = [];
        foreach ($this->replaced['rows'] as $row) {
            $pathsByEntity[$row['entity_id']] = $row['request_path'];
        }

        // The first product keeps the clean path; the second is suffixed with
        // its entity id rather than silently overwriting the first's rewrite.
        self::assertSame('shared-key.html', $pathsByEntity[8454]);
        self::assertSame('shared-key-8816.html', $pathsByEntity[8816]);
        self::assertCount(2, array_unique(array_values($pathsByEntity)));

        self::assertSame([], $context->getMessages('SKU-A'));
        self::assertStringContainsString('is taken in store 1', $context->getMessages('SKU-B')[0]);
    }

    /**
     * @param array<string, int> $entityIdsBySku
     */
    private function createContext(array $entityIdsBySku, string $urlKey): BatchContext
    {
        $products = [];
        $urlKeys = [];
        $websiteIds = [];
        foreach ($entityIdsBySku as $sku => $entityId) {
            $product = new Product();
            $product->setSku($sku);
            $products[] = $product;
            $urlKeys[$sku] = $urlKey;
            $websiteIds[$sku] = [1];
        }

        $context = new BatchContext($products, 0);
        foreach ($entityIdsBySku as $sku => $entityId) {
            $context->setEntityId($sku, $entityId);
        }
        $context->set(EavValueProcessor::CONTEXT_URL_KEYS, $urlKeys);
        $context->set(WebsiteProcessor::CONTEXT_WEBSITE_IDS, $websiteIds);

        return $context;
    }
}
