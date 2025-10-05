<?php
use PHPUnit\Framework\TestCase;
use Arshline\Core\Api;

/**
 * Regression: ensure name + national id + date + informal yes/no preserved & classified.
 */
// Minimal stub if WordPress environment not loaded
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $body; public function __construct(array $p){ $this->body = json_encode($p, JSON_UNESCAPED_UNICODE); }
        public function get_body(){ return $this->body; }
        public function get_json_params(){ return json_decode($this->body,true) ?: []; }
        public function get_param($k){ $d=$this->get_json_params(); return $d[$k]??null; }
        public function offsetGet($k){ $d=$this->get_json_params(); return $d[$k]??null; }
        public function __get($k){ return $this->get_param($k); }
    }
}
// NOTE: Removed early capability stubs (current_user_can, get_current_user_id) to let Patchwork/Brain Monkey manage them.

class HooshaRegressionYesNoNameTest extends TestCase {
    protected function setUp(): void
    {
        if (!function_exists('mb_strtolower')) {
            $this->markTestSkipped('mbstring not available');
        }
    }

    public function test_informal_yes_no_and_name_preserved()
    {
        $input = "اسمت چیه\nتاریخ تولدت چه تاریخیه\nکد ملیتو بده\nامروز میای شرکت ؟";
        // Access protected baseline inference through public preparation pipeline by simulating minimal REST-like call.
        // We call the internal preparation helper via reflection to isolate logic without full WP stack.
        $api = new ReflectionClass(Api::class);
        $method = $api->getMethod('hoosha_prepare');
        $method->setAccessible(true);
        // Create a minimal WP_REST_Request stub with required get_json_params behavior
        // Instead of full REST pipeline (needs many WP funcs), call internal baseline + type inference
        $cls = new ReflectionClass(Api::class);
        $infer = $cls->getMethod('hoosha_local_infer_from_text_v2'); $infer->setAccessible(true);
        $baseline = $infer->invoke(null, $input);
        $this->assertIsArray($baseline,'Baseline not array');
        $fields = $baseline['fields'] ?? [];
    $this->assertTrue(count($fields) >=4 && count($fields) <=5, 'Expected 4-5 fields (name, date, national id, yes/no, optional extra)');

        $labels = array_map(fn($f)=>$f['label'], $fields);
        // Name present
        $this->assertTrue((bool)preg_grep('/نام|اسمت/u',$labels), 'Name field missing');
        // National ID format
        $hasNid = false; $hasDate=false; $hasYesNo=false;
        foreach ($fields as $f){
            $fmt = $f['props']['format'] ?? '';
            if ($fmt === 'national_id_ir') $hasNid = true;
            if (in_array($fmt, ['date_greg','date_jalali'])) $hasDate = true;
            if ($f['type']==='multiple_choice' && !empty($f['props']['options']) && $f['props']['options'] === ['بله','خیر']) $hasYesNo = true;
        }
        $this->assertTrue($hasNid, 'National ID field missing or lost');
        $this->assertTrue($hasDate, 'Date field missing');
        $this->assertTrue($hasYesNo, 'Yes/No inference failed for informal question');
    }
}
