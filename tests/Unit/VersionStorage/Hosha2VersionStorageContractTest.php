<?php
namespace Arshline\Tests\Unit\VersionStorage;

use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\Storage\Hosha2VersionStorageInterface;

abstract class Hosha2VersionStorageContractTest extends TestCase
{
    abstract protected function storage(): Hosha2VersionStorageInterface;

    public function testSaveAndGetRoundTrip(): void
    {
        $s = $this->storage();
        $vid = $s->save(10, ['a'=>1], ['user_prompt'=>'x','diff_applied'=>false]);
        $this->assertIsInt($vid);
        $snap = $s->get($vid);
        $this->assertNotNull($snap);
        $this->assertSame(10, $snap['form_id']);
        $this->assertSame(1, $snap['config']['a']);
        $this->assertArrayHasKey('created_at', $snap);
    }

    public function testListOrderingNewestFirst(): void
    {
        $s = $this->storage();
        $v1 = $s->save(20, ['n'=>1]);
        usleep(1000);
        $v2 = $s->save(20, ['n'=>2]);
        $list = $s->list(20, 10);
        $this->assertCount(2, $list);
        $this->assertSame($v2, $list[0]['version_id']);
        $this->assertSame($v1, $list[1]['version_id']);
    }

    public function testListLimit(): void
    {
        $s = $this->storage();
        for($i=0;$i<5;$i++){ $s->save(30, ['i'=>$i]); }
        $list = $s->list(30, 3);
        $this->assertCount(3, $list);
    }

    public function testPruneKeepsMostRecent(): void
    {
        $s = $this->storage();
        for($i=0;$i<6;$i++){ $s->save(40, ['i'=>$i]); }
        $deleted = $s->prune(40, 2);
        $this->assertGreaterThan(0, $deleted);
        $list = $s->list(40, 10);
        $this->assertCount(2, $list);
        $this->assertGreaterThan($list[1]['version_id'], $list[0]['version_id']);
    }

    public function testGetNotFound(): void
    {
        $s = $this->storage();
        $this->assertNull($s->get(999999));
    }
}
