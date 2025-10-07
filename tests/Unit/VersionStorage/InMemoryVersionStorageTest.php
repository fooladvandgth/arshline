<?php
namespace Arshline\Tests\Unit\VersionStorage;

use Arshline\Hosha2\Storage\{Hosha2VersionStorageInterface,InMemoryVersionStorage};

class InMemoryVersionStorageTest extends Hosha2VersionStorageContractTest
{
    protected function storage(): Hosha2VersionStorageInterface
    {
        return new InMemoryVersionStorage();
    }

    public function testIdsAreMonotonic(): void
    {
        $s = $this->storage();
        $a = $s->save(50, ['x'=>1]);
        $b = $s->save(51, ['x'=>2]);
        $this->assertGreaterThan($a, $b);
    }
}
