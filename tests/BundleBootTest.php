<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests;

use Survos\QuickbaseBundle\Client\QuickbaseClient;
use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;
use Survos\QuickbaseBundle\QuickbaseAppRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\HttpKernel\KernelInterface;

final class BundleBootTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testContainerCompilesAndClientResolves(): void
    {
        self::bootKernel();
        self::assertInstanceOf(QuickbaseClient::class, static::getContainer()->get(QuickbaseClientInterface::class));

        $apps = static::getContainer()->get(QuickbaseAppRegistry::class);
        self::assertInstanceOf(QuickbaseAppRegistry::class, $apps);
        self::assertSame('bwa6visdy', $apps->resolve('lions'));
        self::assertSame('unconfigured-id', $apps->resolve('unconfigured-id'));
        self::assertSame([
            'id' => 'bwa6visd6',
            'fields' => ['record_id' => 3, 'sku' => 6, 'name' => 15],
        ], $apps->table('lions', 'inventory'));
    }

    public function testMetadataCommandsAreRegistered(): void
    {
        self::bootKernel();
        $kernel = static::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        $commands = (new Application($kernel))->all();

        self::assertArrayHasKey('quickbase:apps', $commands);
        self::assertArrayHasKey('quickbase:tables', $commands);
        self::assertArrayHasKey('quickbase:fields', $commands);
        self::assertArrayHasKey('quickbase:relationships', $commands);
        self::assertArrayHasKey('quickbase:query', $commands);
    }
}
