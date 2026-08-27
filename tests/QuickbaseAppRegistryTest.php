<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\Quickbase\QuickbaseAppRegistry;

final class QuickbaseAppRegistryTest extends TestCase
{
    public function testResolvesConfiguredNamesAndPassesThroughRawIds(): void
    {
        $registry = new QuickbaseAppRegistry([
            'lions' => [
                'id' => 'bwa6visdy',
                'tables' => [
                    'inventory' => [
                        'id' => 'bwa6visd6',
                        'fields' => ['record_id' => 3, 'sku' => 6],
                    ],
                ],
            ],
            'rah' => ['id' => 'bv4hfi7e8', 'tables' => []],
        ], 'demo.quickbase.com');

        self::assertSame('bwa6visdy', $registry->resolve('lions'));
        self::assertSame('bv4hfi7e8', $registry->resolve('rah'));
        self::assertSame('brawappid', $registry->resolve('brawappid'));
        self::assertSame('bwa6visd6', $registry->resolveTable('lions.inventory'));
        self::assertSame('brawtableid', $registry->resolveTable('brawtableid'));
        self::assertSame([
            'lions' => [
                'id' => 'bwa6visdy',
                'tables' => [
                    'inventory' => [
                        'id' => 'bwa6visd6',
                        'fields' => ['record_id' => 3, 'sku' => 6],
                    ],
                ],
            ],
            'rah' => ['id' => 'bv4hfi7e8', 'tables' => []],
        ], $registry->all());
        self::assertSame([
            'id' => 'bwa6visd6',
            'fields' => ['record_id' => 3, 'sku' => 6],
        ], $registry->table('lions', 'inventory'));
        self::assertFalse($registry->isReadonly('lions'));
        self::assertSame('https://demo.quickbase.com/db/bwa6visdy', $registry->appUrl('lions'));
        self::assertSame('https://demo.quickbase.com/db/bwa6visd6', $registry->tableUrl('bwa6visd6'));
    }

    public function testResolvesHyphenatedAliasAfterSymfonyNormalizesConfigKey(): void
    {
        $registry = new QuickbaseAppRegistry([
            'lions_ai' => ['id' => 'bwa87p6qv', 'readonly' => true, 'tables' => []],
        ]);

        self::assertSame('bwa87p6qv', $registry->resolve('lions-ai'));
        self::assertTrue($registry->isReadonly('lions-ai'));
    }
}
