<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hoosha\Pipeline\HooshaService;

class FinalizeHeuristicsTest extends TestCase
{
    public function testLongTextUpgrade(): void
    {
        $svc = new HooshaService(null);
        $res = $svc->process("توضیح بده", []);
        $fields = $res['schema']['fields'] ?? [];
        $this->assertTrue(count($fields)>0);
        $found=false; foreach($fields as $f){ if(($f['label']??'')==='توضیح بده' && ($f['type']??'')==='long_text') $found=true; }
        $this->assertTrue($found,'Expected long_text upgrade');
        $this->assertContains('heur:long_text_upgrade',$res['notes']);
    }

    public function testTodayDateInference(): void
    {
        $svc = new HooshaService(null);
        $res = $svc->process("امروز چندمه\nاسمت چیه", []);
        $fields = $res['schema']['fields'] ?? [];
        $hasToday=false; foreach($fields as $f){ if(($f['props']['source']??'')==='inferred_today') $hasToday=true; }
        $this->assertTrue($hasToday,'Expected inferred today date field');
        $this->assertContains('heur:today_date_inferred',$res['notes']);
    }
}
