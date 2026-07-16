<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Cache;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\CategoryFactory;
use ReadyData\Import\Logger\Logger;
use ReadyData\Import\Model\Category\PathParser;
use ReadyData\Import\Model\ResourceModel\Category as CategoryResource;

/**
 * Request-scoped category path => ID resolver with auto-creation of missing
 * subtrees. Must stay a shared instance (see di.xml).
 *
 * Paths are matched segment-by-segment against store-0 names (exact match,
 * segments pre-trimmed by PathParser). The first segment must name an
 * existing level-1 root — roots are never auto-created, so a typo cannot
 * spawn a new tree. Missing segments below a root are created through the
 * category repository: the model save maintains path/level/children_count,
 * generates the url_key and category URL rewrites, and handles EE row_id —
 * a deliberate exception to the module's direct-SQL rule, bounded by the
 * low cardinality of distinct new paths per request.
 *
 * Categories created inside a batch transaction vanish if that batch rolls
 * back; entries this resolver created itself are therefore re-verified on
 * every call and evicted (and re-created on demand) when gone.
 */
class CategoryPathResolver
{
    /**
     * @var array<string, int> normalized path cache key => entity_id
     */
    private array $idByPath = [];

    /**
     * @var array<string, true> cache keys created by this resolver
     */
    private array $createdPaths = [];

    /**
     * @var array<string, int>|null store-0 root name => entity_id
     */
    private ?array $roots = null;

    /**
     * @var string[]|null required int attributes to zero-fill on creation
     */
    private ?array $requiredIntAttributes = null;

    public function __construct(
        private readonly CategoryResource $categoryResource,
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly Logger $logger
    ) {
    }

    /**
     * Bulk-resolve normalized paths, creating missing subtrees below
     * existing roots. One tree query per depth level, never per path.
     *
     * @param array<string, string[]> $paths cache key => trimmed segments
     * @return array<string, array{id: ?int, message: ?string}> keyed like
     *         $paths; id is null when unresolved and message explains why
     */
    public function resolvePaths(array $paths): array
    {
        $this->evictRolledBackCreations();

        $results = [];
        $walks = [];

        foreach ($paths as $key => $segments) {
            if (isset($this->idByPath[$key])) {
                $results[$key] = ['id' => $this->idByPath[$key], 'message' => null];
                continue;
            }

            if (count($segments) === 1) {
                $results[$key] = [
                    'id' => null,
                    'message' => sprintf('Cannot assign products to the root category "%s".', $segments[0]),
                ];
                continue;
            }

            $rootId = $this->getRoots()[$segments[0]] ?? null;
            if ($rootId === null) {
                $results[$key] = [
                    'id' => null,
                    'message' => sprintf(
                        'Unknown root category "%s" — root categories are not auto-created.',
                        $segments[0]
                    ),
                ];
                continue;
            }

            // depth = number of segments already resolved; parentId = ID of
            // the last resolved segment.
            $walks[$key] = ['segments' => $segments, 'depth' => 1, 'parentId' => $rootId];
        }

        // Walk the existing tree level by level for all paths at once.
        while ($walks) {
            foreach ($walks as $key => &$walk) {
                $this->advanceThroughCache($walk);
                if ($walk['depth'] >= count($walk['segments'])) {
                    $results[$key] = ['id' => $walk['parentId'], 'message' => null];
                    unset($walks[$key]);
                }
            }
            unset($walk);

            if (!$walks) {
                break;
            }

            $children = $this->categoryResource->getChildrenByParentIds(
                array_values(array_unique(array_column($walks, 'parentId')))
            );

            foreach ($walks as $key => &$walk) {
                $segment = $walk['segments'][$walk['depth']];
                $childId = $children[$walk['parentId']][$segment] ?? null;

                if ($childId === null) {
                    // Tree walk stalled: the rest of the chain is missing.
                    $results[$key] = $this->createChain($walk);
                    unset($walks[$key]);
                    continue;
                }

                $this->idByPath[$this->prefixKey($walk)] = $childId;
                $walk['parentId'] = $childId;
                $walk['depth']++;

                if ($walk['depth'] >= count($walk['segments'])) {
                    $results[$key] = ['id' => $childId, 'message' => null];
                    unset($walks[$key]);
                }
            }
            unset($walk);
        }

        return $results;
    }

    /**
     * Validate numeric category references in bulk. Usable = existing and
     * below a root (level >= 2); root and unknown IDs are absent from the
     * result.
     *
     * @param int[] $categoryIds
     * @return array<int, bool> entity_id => true for usable categories
     */
    public function validateIds(array $categoryIds): array
    {
        $this->evictRolledBackCreations();

        if (!$categoryIds) {
            return [];
        }

        $valid = [];
        $existing = $this->categoryResource->getExistingByIds(array_values(array_unique($categoryIds)));
        foreach ($existing as $id => $row) {
            if ($row['level'] >= 2) {
                $valid[$id] = true;
            }
        }

        return $valid;
    }

    /**
     * Advance a walk through prefixes already resolved in the cache.
     *
     * @param array{segments: string[], depth: int, parentId: int} $walk
     */
    private function advanceThroughCache(array &$walk): void
    {
        while ($walk['depth'] < count($walk['segments'])) {
            $cachedId = $this->idByPath[$this->prefixKey($walk)] ?? null;
            if ($cachedId === null) {
                return;
            }
            $walk['parentId'] = $cachedId;
            $walk['depth']++;
        }
    }

    /**
     * Create the missing tail of a stalled walk, parent-first. A creation
     * failure (e.g. a url-key conflict raised by a plugin) is reported per
     * path, never thrown — a category problem must not roll back the batch.
     *
     * @param array{segments: string[], depth: int, parentId: int} $walk
     * @return array{id: ?int, message: ?string}
     */
    private function createChain(array $walk): array
    {
        $parentId = $walk['parentId'];

        for ($i = $walk['depth'], $count = count($walk['segments']); $i < $count; $i++) {
            $prefixKey = implode(
                PathParser::SEPARATOR,
                array_slice($walk['segments'], 0, $i + 1)
            );

            // A chain created moments ago by another path may already cover
            // this prefix.
            if (isset($this->idByPath[$prefixKey])) {
                $parentId = $this->idByPath[$prefixKey];
                continue;
            }

            try {
                $category = $this->categoryFactory->create();
                $category->setName($walk['segments'][$i]);
                $category->setParentId($parentId);
                $category->setIsActive(true);
                $category->setData('include_in_menu', 1);
                $category->setStoreId(0);
                // Required custom yes/no attributes with no default would
                // fail validation on save; fill them with "No".
                foreach ($this->getRequiredIntAttributes() as $code) {
                    if ($category->getData($code) === null) {
                        $category->setData($code, 0);
                    }
                }
                // The repository save fills path/level/children_count,
                // generates the url_key and category URL rewrites.
                $parentId = (int)$this->categoryRepository->save($category)->getId();
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Failed to create category "%s": %s', $prefixKey, $e->getMessage()),
                    ['exception' => $e]
                );

                return [
                    'id' => null,
                    'message' => sprintf(
                        'Failed to create category "%s": %s',
                        $prefixKey,
                        $e->getMessage()
                    ),
                ];
            }

            $this->idByPath[$prefixKey] = $parentId;
            $this->createdPaths[$prefixKey] = true;
        }

        return ['id' => $parentId, 'message' => null];
    }

    /**
     * Drop cached entries for categories this resolver created that no
     * longer exist — their creating batch was rolled back. They are
     * re-created on the next resolution that needs them.
     */
    private function evictRolledBackCreations(): void
    {
        if (!$this->createdPaths) {
            return;
        }

        $createdIds = array_intersect_key($this->idByPath, $this->createdPaths);
        $existing = $this->categoryResource->getExistingByIds(array_values($createdIds));

        foreach ($createdIds as $key => $id) {
            if (!isset($existing[$id])) {
                unset($this->idByPath[$key], $this->createdPaths[$key]);
                $this->logger->info(sprintf(
                    'Auto-created category "%s" (ID %d) was rolled back with its batch; it will be re-created on demand.',
                    $key,
                    $id
                ));
            }
        }
    }

    /**
     * Cache key of the next unresolved prefix of a walk.
     *
     * @param array{segments: string[], depth: int, parentId: int} $walk
     */
    private function prefixKey(array $walk): string
    {
        return implode(
            PathParser::SEPARATOR,
            array_slice($walk['segments'], 0, $walk['depth'] + 1)
        );
    }

    /**
     * @return string[]
     */
    private function getRequiredIntAttributes(): array
    {
        return $this->requiredIntAttributes
            ??= $this->categoryResource->getRequiredIntAttributesWithoutDefault();
    }

    /**
     * @return array<string, int>
     */
    private function getRoots(): array
    {
        // Roots are never auto-created, so this cache cannot go stale
        // through a rollback.
        return $this->roots ??= $this->categoryResource->getRootCategories();
    }
}
