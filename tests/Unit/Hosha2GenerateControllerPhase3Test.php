<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\{Hosha2GenerateService,Hosha2CapabilitiesBuilder,Hosha2OpenAIEnvelopeFactory,Hosha2OpenAIClientStub,Hosha2DiffValidator,Hosha2GenerateController,Hosha2VersionRepository};

// Lightweight WP REST shims if not present (PHPUnit standalone context)
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($arr,$err=false){ static $id=9000; $id++; $arr['ID']=$id; $arr['post_type']='hosha2_version'; global $hosha2_p3_posts; $hosha2_p3_posts[$id]=$arr; return $id; }
    function update_post_meta($id,$k,$v){ global $hosha2_p3_meta; $hosha2_p3_meta[$id][$k]=$v; }
    function get_post($id){ global $hosha2_p3_posts; return $hosha2_p3_posts[$id]??null; }
    function get_post_meta($id,$k,$single){ global $hosha2_p3_meta; return $hosha2_p3_meta[$id][$k]??null; }
    function is_wp_error($thing){ return false; }
}
if (!class_exists('WP_REST_Request')) {
    // Minimal polyfill for tests (only used methods)
    class WP_REST_Request {
        protected string $method; protected string $route; protected array $params = [];
        public function __construct($m,$r){ $this->method=$m; $this->route=$r; }
        public function set_param($k,$v){ $this->params[$k]=$v; }
        public function get_param($k){ return $this->params[$k]??null; }
        public function __get($k){ return $this->params[$k]??null; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        protected $data; protected int $status; public function __construct($d,$s=200){ $this->data=$d; $this->status=$s; }
        public function get_data(){ return $this->data; }
        public function get_status(){ return $this->status; }
    }
}

class Hosha2GenerateControllerPhase3Test extends TestCase
{
    protected function makeService(callable $mutator = null): Hosha2GenerateService
    {
        $client = new class extends Hosha2OpenAIClientStub {
            public function sendGenerate(array $env): array {
                return [
                    'final_form' => [
                        'version' => 'arshline_form@v1',
                        'fields' => [ ['id'=>'f1','type'=>'text'] ],
                        'layout' => [],
                        'meta' => ['gen'=>true]
                    ],
                    'diff' => [ ['op'=>'add','path'=>'/fields/0','value'=>['id'=>'f1','type'=>'text']] ],
                    'token_usage' => ['prompt'=>11,'completion'=>19,'total'=>30]
                ];
            }
        };
        $svc = new Hosha2GenerateService(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            $client,
            new Hosha2DiffValidator(),
            null,
            null,
            new Hosha2VersionRepository(null)
        );
        if ($mutator) { $mutator($svc); }
        return $svc;
    }

    protected function invoke(array $overrides = []): array
    {
        $controller = new Hosha2GenerateController($this->makeService());
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/101/generate');
        $req->set_param('form_id', 101);
        $req->set_param('prompt', 'فرم ثبت نام ساده');
        foreach($overrides as $k=>$v) $req->set_param($k,$v);
        $resp = $controller->handle($req);
        return [$resp->get_status(), $resp->get_data()];
    }

    // Test 9: basic successful response top-level + data keys
    public function testPhase3_09_BasicShape()
    {
        [$status,$data] = $this->invoke();
        $this->assertSame(200,$status,'Expected HTTP 200 on success');
        foreach(['success','request_id','version_id','diff_sha','data'] as $k) $this->assertArrayHasKey($k,$data);
        $this->assertTrue($data['success']);
        $payload = $data['data'];
        foreach(['final_form','diff','token_usage','progress','progress_percent'] as $k) $this->assertArrayHasKey($k,$payload);
        $this->assertIsArray($payload['diff']);
        $this->assertIsArray($payload['progress']);
        $this->assertIsFloat($payload['progress_percent']);
    }

    // Test 10: progress phases ordering & completeness
    public function testPhase3_10_ProgressOrdering()
    {
        [, $data] = $this->invoke();
        $expected = ['analyze_input','build_capabilities','openai_send','validate_response','persist_form','render_preview'];
        $progress = $data['data']['progress'];
        $this->assertEquals($expected,$progress,'Progress phases must be complete and ordered exactly');
        $pct = $data['data']['progress_percent'];
        $this->assertGreaterThan(0.99,$pct,'Progress percent should be ~1.0 at completion');
        $this->assertLessThanOrEqual(1.0,$pct);
    }

    // Test 11: diff_sha correctness (sha1 of diff json)
    public function testPhase3_11_DiffShaMatches()
    {
        [, $data] = $this->invoke();
        $diff = $data['data']['diff'];
        // Service uses json_encode without flags when computing sha1
        $encoded = json_encode($diff);
        $expectedSha = sha1($encoded);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/',$data['diff_sha'],'diff_sha must be 40 hex chars');
        $this->assertSame($expectedSha,$data['diff_sha'],'diff_sha must equal sha1(json_encode(original diff))');
    }

    // Test 12: token usage numeric & consistent
    public function testPhase3_12_TokenUsage()
    {
        [, $data] = $this->invoke();
        $usage = $data['data']['token_usage'];
        foreach(['prompt','completion','total'] as $k) $this->assertArrayHasKey($k,$usage);
        $this->assertIsInt($usage['prompt']);
        $this->assertIsInt($usage['completion']);
        $this->assertIsInt($usage['total']);
        $this->assertSame($usage['prompt'] + $usage['completion'], $usage['total'], 'total must equal prompt+completion');
        $this->assertGreaterThan(0,$usage['prompt']);
        $this->assertGreaterThan(0,$usage['completion']);
    }
}
