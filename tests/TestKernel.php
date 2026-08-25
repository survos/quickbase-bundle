<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Tests;

use Survos\Kit\SurvosKitBundle;
use Survos\QuickbaseBundle\SurvosQuickbaseBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\DependencyInjection\Kernel\BundleInterface;

final class TestKernel extends Kernel
{
    /** @return \Generator<int, BundleInterface> */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SurvosKitBundle();
        yield new SurvosQuickbaseBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', ['secret' => 'test', 'test' => true, 'http_client' => []]);
            $container->loadFromExtension('survos_quickbase', [
                'realm' => 'example.quickbase.com',
                'token' => 'test-token',
                'apps' => [
                    'lions' => [
                        'id' => 'bwa6visdy',
                        'tables' => [
                            'inventory' => [
                                'id' => 'bwa6visd6',
                                'fields' => ['record_id' => 3, 'sku' => 6, 'name' => 15],
                            ],
                        ],
                    ],
                    'rah' => ['id' => 'bv4hfi7e8'],
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/quickbase-bundle-tests/cache/'.spl_object_hash($this);
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/quickbase-bundle-tests/log';
    }
}
