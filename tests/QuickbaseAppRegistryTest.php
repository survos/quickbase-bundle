<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\QuickbaseBundle\QuickbaseAppRegistry;

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
        ]);

        self::assertSame('bwa6visdy', $registry->resolve('lions'));
        self::assertSame('bv4hfi7e8', $registry->resolve('rah'));
        self::assertSame('brawappid', $registry->resolve('brawappid'));
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
    }
}
