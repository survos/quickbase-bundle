<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests;

use Survos\QuickbaseBundle\Client\QuickbaseClient;
use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;

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
    }
}
