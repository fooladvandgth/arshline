<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Core\Api;

/**
 * Verifies that an enumerated Persian checklist with >5 items yields all distinct fields,
 * including yes/no mapping and rating detection.
 */
class HooshaPrepareEnumeratedChecklistTest extends TestCase
{
    public function test_enumerated_checklist_parses_all_questions(): void
    {
        $root = dirname(__DIR__,2);
        $vendor = $root.'/vendor/autoload.php';
        if (is_readable($vendor)) require_once $vendor;
        if (!class_exists(Api::class) && is_readable($root.'/src/Core/Api.php')) require_once $root.'/src/Core/Api.php';

        $text = "1. نام و نام خانوادگی\n2. آیا عضو باشگاه هستید؟\n3. سن فعلی شما؟\n4. قد (سانتی متر)؟\n5. وزن فعلی؟\n6. ایمیل\n7. شماره موبایل\n8. کد ملی\n9. شهر محل سکونت\n10. سطح رضایت از سرویس (1 تا 10)\n11. توضیح مختصر از هدف شما\n12. آیا مایل به دریافت خبرنامه هستید؟\n13. میزان علاقه کلی (1 تا 10)";

        // Invoke protected static method via reflection
        $ref = new \ReflectionClass(Api::class);
        $m = $ref->getMethod('hoosha_local_infer_from_text_v2');
        $m->setAccessible(true);
        $baseline = $m->invoke(null, $text);
        $this->assertIsArray($baseline,'Baseline should be array');
        $fields = $baseline['fields'] ?? [];
        $this->assertIsArray($fields,'Fields should be array');
        $this->assertGreaterThanOrEqual(13, count($fields), 'Should infer at least 13 fields from enumerated list');

        $yesNoFound=false; $ratingFound=0;
        foreach ($fields as $f){
            if (!is_array($f) || empty($f['label'])) continue;
            $lbl = $f['label'];
            // Ensure numeric prefixes stripped
            $this->assertFalse((bool)preg_match('/^[0-9۰-۹]+[\.\):\-]/u',$lbl), 'Label still has numeric prefix: '.$lbl);
            if (mb_strpos($lbl,'آیا عضو باشگاه') !== false){
                $this->assertEquals('multiple_choice',$f['type']);
                $this->assertEquals(['بله','خیر'],$f['props']['options'] ?? []);
                $yesNoFound=true;
            }
            if (($f['type'] ?? '')==='rating') $ratingFound++;
        }
        $this->assertTrue($yesNoFound,'Yes/No question not converted to multiple_choice');
        $this->assertGreaterThanOrEqual(2,$ratingFound,'Expected two rating fields (questions 10 & 13)');
    }
}
