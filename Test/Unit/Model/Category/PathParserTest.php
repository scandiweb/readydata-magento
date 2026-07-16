<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Category;

use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Category\PathParser;

class PathParserTest extends TestCase
{
    /**
     * @dataProvider parseDataProvider
     */
    public function testParse(string $reference, ?array $expected): void
    {
        self::assertSame($expected, (new PathParser())->parse($reference));
    }

    /**
     * @return array<string, array{string, ?array}>
     */
    public static function parseDataProvider(): array
    {
        return [
            'numeric id' => ['42', ['type' => 'id', 'id' => 42]],
            'numeric id padded' => [' 42 ', ['type' => 'id', 'id' => 42]],
            'simple path' => [
                'Default Category/Men/Shirts',
                ['type' => 'path', 'segments' => ['Default Category', 'Men', 'Shirts']],
            ],
            'name with digits is a path' => [
                '2024 Collection',
                ['type' => 'path', 'segments' => ['2024 Collection']],
            ],
            'segments trimmed' => [
                'Default Category / Men /Shirts ',
                ['type' => 'path', 'segments' => ['Default Category', 'Men', 'Shirts']],
            ],
            'leading, trailing and double separators dropped' => [
                '/A//B/',
                ['type' => 'path', 'segments' => ['A', 'B']],
            ],
            'single segment (root reference)' => [
                'Default Category',
                ['type' => 'path', 'segments' => ['Default Category']],
            ],
            'empty' => ['', null],
            'whitespace only' => ['   ', null],
            'bare separator' => ['/', null],
            'separators and whitespace only' => [' / / ', null],
            'negative number is a path' => [
                '-1',
                ['type' => 'path', 'segments' => ['-1']],
            ],
        ];
    }
}
