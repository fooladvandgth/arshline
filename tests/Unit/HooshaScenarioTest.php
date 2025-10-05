<?php
use PHPUnit\Framework\TestCase;
use Arshline\Core\Api;

// NOTE: Removed early procedural WP function stubs (current_user_can, get_current_user_id) to avoid
// Patchwork DefinedTooEarly errors. Brain Monkey / Patchwork will handle interception if needed.
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $body; public function __construct(array $p){ $this->body = json_encode($p, JSON_UNESCAPED_UNICODE); }
        public function get_body(){ return $this->body; }
        public function get_json_params(){ return json_decode($this->body,true) ?: []; }
        public function get_param($k){ $d=$this->get_json_params(); return $d[$k]??null; }
    }
}

/**
 * Comprehensive scenario-driven test for Hoosha inference pipeline.
 * Each scenario defines an input questionnaire and expected structural assertions.
 */
class HooshaScenarioTest extends TestCase
{
    protected function prepare(string $text): array
    {
        $cls = new ReflectionClass(Api::class);
        $infer = $cls->getMethod('hoosha_local_infer_from_text_v2');
        $infer->setAccessible(true);
        $baseline = $infer->invoke(null, $text);
        // Simulate outer wrapper structure { schema: { fields: [...] } }
        return [ 'schema' => $baseline ];
    }

    public function test_scenarios()
    {
        $scenarios = [
            'baseline_simple' => [
                'input' => "نام شما چیست؟\nایمیل\nکد ملی\nتاریخ تولد\nشماره موبایل",
                'assert' => function($r){
                    $fields = $r['schema']['fields'];
                    $formats = array_map(fn($f)=>$f['props']['format']??'', $fields);
                    $this->assertContains('national_id_ir',$formats,'National ID missing');
                    $this->assertTrue(count(array_filter($formats,fn($x)=>str_starts_with((string)$x,'date_')))>=1,'Date not inferred');
                }
            ],
            'informal_yesno' => [
                'input' => "اسمت چیه\nامروز میای شرکت ؟",
                'assert' => function($r){
                    $fields = $r['schema']['fields'];
                    $yesNo = array_filter($fields, fn($f)=>$f['type']==='multiple_choice' && (($f['props']['options']??[])===['بله','خیر']));
                    $this->assertNotEmpty($yesNo,'Yes/No not detected');
                }
            ],
            'enumerated_checklist' => [
                'input' => "1) نام\n2) ایمیل\n3) کد ملی\n4) تاریخ ثبت\n5) شماره تماس",
                'assert' => function($r){
                    $labels = array_map(fn($f)=>$f['label'],$r['schema']['fields']);
                    $this->assertFalse((bool)preg_grep('/^1\)/',$labels),'Numeric prefix not stripped');
                }
            ],
            'rating_detection' => [
                'input' => "میزان رضایت خود را از 1 تا 10 وارد کنید",
                'assert' => function($r){
                    $rating = array_filter($r['schema']['fields'], fn($f)=>$f['type']==='rating');
                    $this->assertNotEmpty($rating,'Rating not detected');
                }
            ],
            'hallucination_noise' => [
                'input' => "نام\nکد ملی\n*** تورنادو کوانتومی بنفش ***\nایمیل",
                'assert' => function($r){
                    $labels = array_map(fn($f)=>$f['label'],$r['schema']['fields']);
                    $this->assertNotContains('*** تورنادو کوانتومی بنفش ***',$labels,'Noise not pruned');
                }
            ],
        ];

        $failures = [];
        foreach ($scenarios as $key=>$sc){
            try {
                $res = $this->prepare($sc['input']);
                $this->assertIsArray($res,'Result structure invalid');
                $this->assertArrayHasKey('schema',$res,'Missing schema');
                $this->assertIsArray($res['schema']['fields']??null,'Fields missing');
                ($sc['assert'])($res);
            } catch (AssertionError $e){
                $failures[$key] = $e->getMessage();
            } catch (Throwable $e){
                $failures[$key] = 'Exception: '.$e->getMessage();
            }
        }

        if (!empty($failures)){
            $this->fail('Scenario failures: '.json_encode($failures, JSON_UNESCAPED_UNICODE));
        }
        $this->assertTrue(true,'All scenarios passed');
    }
}
