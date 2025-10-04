<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Hoosha\HooshaBaselineInferer;
use Arshline\Core\Api; // ensure Api loaded for reflection target

class HooshaBaselineInfererTest extends TestCase
{
    protected function ensureLoaded(): void
    {
        $root = dirname(__DIR__,2);
        $vendor = $root.'/vendor/autoload.php';
        if (is_readable($vendor)) require_once $vendor;
        if (!class_exists(Api::class) && is_readable($root.'/src/Core/Api.php')) require_once $root.'/src/Core/Api.php';
        if (!class_exists(HooshaBaselineInferer::class) && is_readable($root.'/src/Hoosha/HooshaBaselineInferer.php')) require_once $root.'/src/Hoosha/HooshaBaselineInferer.php';
    }

    public function test_enumeration_and_yesno_and_rating()
    {
        $this->ensureLoaded();
        $text = "1. نام و نام خانوادگی\n2. آیا عضو باشگاه هستید؟\n3. میزان علاقه کلی (1 تا 10)";
        $res = HooshaBaselineInferer::infer($text);
        $this->assertIsArray($res);
        $fields = $res['fields'] ?? [];
        $this->assertIsArray($fields);
        $this->assertGreaterThanOrEqual(3,count($fields));
        $labels = array_map(fn($f)=>$f['label']??'', $fields);
        $foundYesNo = false; $foundRating=false;
        foreach ($fields as $f){
            if (!is_array($f)) continue;
            if (isset($f['label']) && str_contains($f['label'],'آیا عضو باشگاه')){
                $this->assertEquals('multiple_choice',$f['type']);
                $this->assertEquals(['بله','خیر'],$f['props']['options'] ?? []);
                $foundYesNo=true;
            }
            if (($f['type'] ?? '')==='rating') $foundRating=true;
        }
        $this->assertTrue($foundYesNo,'Yes/No not detected');
        $this->assertTrue($foundRating,'Rating not detected');
    }
}
