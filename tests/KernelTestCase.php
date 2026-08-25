<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase as SymfonyKernelTestCase;

abstract class KernelTestCase extends SymfonyKernelTestCase
{
    protected static function ensureKernelShutdown(): void
    {
        $wasBooted = static::$booted;
        parent::ensureKernelShutdown();
        if ($wasBooted) {
            restore_exception_handler();
        }
    }
}
