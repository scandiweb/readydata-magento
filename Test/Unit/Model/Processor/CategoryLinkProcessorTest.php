<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\BatchContext;
use ReadyData\Import\Model\Cache\CategoryPathResolver;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\Data\Product;
use ReadyData\Import\Model\Processor\CategoryLinkProcessor;
use ReadyData\Import\Model\ResourceModel\CategoryLink;

class CategoryLinkProcessorTest extends TestCase
{
    private CategoryLink&MockObject $categoryLink;
    private CategoryPathResolver&MockObject $pathResolver;
    private CategoryLinkProcessor $processor;

    protected function setUp(): void
    {
        $this->categoryLink = $this->createMock(CategoryLink::class);
        $this->pathResolver = $this->createMock(CategoryPathResolver::class);
        $this->processor = new CategoryLinkProcessor(
            $this->categoryLink,
            $this->pathResolver,
            new PathParser()
        );
    }

    public function testReplaceInsertsNewAndDeletesRemovedLinks(): void
    {
        $context = $this->createContext(['SKU-1' => ['Default Category/Men/Shirts', '42']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')
            ->with(['Default Category/Men/Shirts' => ['Default Category', 'Men', 'Shirts']])
            ->willReturn(['Default Category/Men/Shirts' => ['id' => 5, 'message' => null]]);
        $this->pathResolver->method('validateIds')->with([42])->willReturn([42 => true]);
        $this->categoryLink->method('getAssignments')->with([10])->willReturn([10 => [42, 7]]);

        $this->categoryLink->expects(self::once())->method('unassign')
            ->with([['category_id' => 7, 'product_id' => 10]]);
        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 5, 'product_id' => 10, 'position' => 0]]);

        $this->processor->process($context);

        self::assertSame([], $context->getMessages('SKU-1'));
        self::assertEqualsCanonicalizing(
            [5, 7],
            $context->get(CategoryLinkProcessor::CONTEXT_AFFECTED_CATEGORY_IDS)
        );
    }

    public function testEmptyArrayRemovesAllAssignments(): void
    {
        $context = $this->createContext(['SKU-1' => []], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')->with([])->willReturn([]);
        $this->pathResolver->method('validateIds')->with([])->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [5, 7]]);

        $this->categoryLink->expects(self::once())->method('unassign')
            ->with([
                ['category_id' => 5, 'product_id' => 10],
                ['category_id' => 7, 'product_id' => 10],
            ]);
        $this->categoryLink->expects(self::once())->method('assign')->with([]);

        $this->processor->process($context);
    }

    public function testNullCategoriesTouchesNothing(): void
    {
        $product = new Product();
        $product->setSku('SKU-1');
        $context = new BatchContext([$product]);
        $context->setEntityId('SKU-1', 10);

        $this->pathResolver->expects(self::never())->method('resolvePaths');
        $this->pathResolver->expects(self::never())->method('validateIds');
        $this->categoryLink->expects(self::never())->method('getAssignments');
        $this->categoryLink->expects(self::never())->method('unassign');
        $this->categoryLink->expects(self::never())->method('assign');

        $this->processor->process($context);

        self::assertNull($context->get(CategoryLinkProcessor::CONTEXT_AFFECTED_CATEGORY_IDS));
    }

    public function testUnresolvedReferenceWithholdsDeletionsAndWarns(): void
    {
        $context = $this->createContext(['SKU-1' => ['Default Category/Nope', '5']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')->willReturn([
            'Default Category/Nope' => ['id' => null, 'message' => 'Unknown root category "Default Category" — root categories are not auto-created.'],
        ]);
        $this->pathResolver->method('validateIds')->with([5])->willReturn([5 => true]);
        $this->categoryLink->method('getAssignments')->willReturn([10 => [7]]);

        $this->categoryLink->expects(self::once())->method('unassign')->with([]);
        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 5, 'product_id' => 10, 'position' => 0]]);

        $this->processor->process($context);

        $messages = $context->getMessages('SKU-1');
        self::assertCount(2, $messages);
        self::assertStringContainsString('Unknown root category', $messages[0]);
        self::assertStringContainsString('applied additively', $messages[1]);
        self::assertFalse($context->isFailed('SKU-1'));
    }

    public function testUnknownCategoryIdWarnsAndIsSkipped(): void
    {
        $context = $this->createContext(['SKU-1' => ['99']], ['SKU-1' => 10]);

        $this->pathResolver->method('resolvePaths')->willReturn([]);
        $this->pathResolver->method('validateIds')->with([99])->willReturn([]);
        $this->categoryLink->method('getAssignments')->willReturn([]);

        $this->categoryLink->expects(self::once())->method('unassign')->with([]);
        $this->categoryLink->expects(self::once())->method('assign')->with([]);

        $this->processor->process($context);

        $messages = $context->getMessages('SKU-1');
        self::assertCount(1, $messages);
        self::assertStringContainsString('Unknown or root category ID 99', $messages[0]);
        self::assertFalse($context->isFailed('SKU-1'));
    }

    public function testProductWithoutEntityIdIsSkipped(): void
    {
        $context = $this->createContext(['SKU-1' => ['42']], []);

        $this->pathResolver->expects(self::never())->method('validateIds');
        $this->categoryLink->expects(self::never())->method('assign');

        $this->processor->process($context);
    }

    public function testDuplicateAndEquivalentReferencesAreDeduplicated(): void
    {
        $context = $this->createContext(
            ['SKU-1' => ['Default Category/Men', 'Default Category/Men/', '42', ' 42 ']],
            ['SKU-1' => 10]
        );

        $this->pathResolver->method('resolvePaths')
            ->with(['Default Category/Men' => ['Default Category', 'Men']])
            ->willReturn(['Default Category/Men' => ['id' => 42, 'message' => null]]);
        $this->pathResolver->method('validateIds')->with([42])->willReturn([42 => true]);
        $this->categoryLink->method('getAssignments')->willReturn([]);

        $this->categoryLink->expects(self::once())->method('assign')
            ->with([['category_id' => 42, 'product_id' => 10, 'position' => 0]]);

        $this->processor->process($context);
    }

    /**
     * @param array<string, string[]> $categoriesBySku
     * @param array<string, int> $entityIds
     */
    private function createContext(array $categoriesBySku, array $entityIds): BatchContext
    {
        $products = [];
        foreach ($categoriesBySku as $sku => $categories) {
            $product = new Product();
            $product->setSku($sku);
            $product->setCategories($categories);
            $products[] = $product;
        }

        $context = new BatchContext($products);
        foreach ($entityIds as $sku => $entityId) {
            $context->setEntityId($sku, $entityId);
        }

        return $context;
    }
}
