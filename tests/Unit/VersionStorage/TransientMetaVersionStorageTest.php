<?php
namespace Arshline\Tests\Unit\VersionStorage;

use Arshline\Hosha2\Storage\{Hosha2VersionStorageInterface,TransientMetaVersionStorage};

class TransientMetaVersionStorageTest extends Hosha2VersionStorageContractTest
{
    protected function storage(): Hosha2VersionStorageInterface
    {
        // In test context (no WP functions) this will fallback to InMemory adapter automatically.
        return new TransientMetaVersionStorage();
    }
}
