<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Adapter;

use Survos\QuickbaseBundle\Contract\QuickbaseClientInterface;
use Survos\RecordStoreBundle\Contract\AdapterFactoryInterface;
use Survos\RecordStoreBundle\Contract\RecordStoreAdapterInterface;
use Survos\RecordStoreBundle\Model\ConnectionConfiguration;

final readonly class QuickbaseAdapterFactory implements AdapterFactoryInterface
{
    public function __construct(private QuickbaseClientInterface $client)
    {
    }

    public function supports(string $driver): bool
    {
        return 'quickbase' === strtolower($driver);
    }

    public function create(ConnectionConfiguration $connection): RecordStoreAdapterInterface
    {
        return new QuickbaseAdapter($this->client);
    }
}
