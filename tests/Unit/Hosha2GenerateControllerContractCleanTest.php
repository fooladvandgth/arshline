<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\{Hosha2GenerateService,Hosha2CapabilitiesBuilder,Hosha2OpenAIEnvelopeFactory,Hosha2OpenAIClientStub,Hosha2DiffValidator,Hosha2GenerateController,Hosha2VersionRepository};

// Minimal WP shims only if absent
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($arr, $return_wp_error=false) { static $id=8000; $id++; $arr['ID']=$id; $arr['post_date']=date('Y-m-d H:i:s'); $arr['post_type']='hosha2_version'; global $hosha2_contract_posts; $hosha2_contract_posts[$id]=$arr; return $id; }
    function is_wp_error($thing){ return false; }
    function update_post_meta($id,$k,$v){ global $hosha2_contract_meta; $hosha2_contract_meta[$id][$k]=$v; }
    function get_post($id){ global $hosha2_contract_posts; return $hosha2_contract_posts[$id]??null; }
    function get_post_meta($id,$k,$single){ global $hosha2_contract_meta; return $hosha2_contract_meta[$id][$k]??''; }
}

class Hosha2GenerateControllerContractCleanTest extends TestCase
{
    protected function skipIfNoWP(): void { if (!class_exists('WP_REST_Request')) $this->markTestSkipped('WP REST classes not loaded'); }

    protected function makeService(): Hosha2GenerateService
    {
        $client = new class extends Hosha2OpenAIClientStub { public function sendGenerate(array $env): array { return [ 'final_form'=>['version'=>'arshline_form@v1','fields'=>[['id'=>'fA','type'=>'text']],'layout'=>[],'meta'=>['gen'=>true]], 'diff'=>[['op'=>'add','path'=>'/fields/0','value'=>['id'=>'fA']]], 'token_usage'=>['prompt'=>5,'completion'=>7,'total'=>12] ]; } };
        return new Hosha2GenerateService(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            $client,
            new Hosha2DiffValidator(),
            null,
            null,
            new Hosha2VersionRepository(null)
        );
    }

    public function testSuccessContract()
    {
        $this->skipIfNoWP();
        $controller = new Hosha2GenerateController($this->makeService());
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/555/generate');
        $req->set_param('form_id',555); $req->set_param('prompt','فرم ساده تایست کانترکت');
        $resp = $controller->handle($req); $status=$resp->get_status(); $data=$resp->get_data();
        if ($status !== 200) $this->fail('Expected 200 got '.$status.' payload='.json_encode($data,JSON_UNESCAPED_UNICODE));
        $this->assertTrue($data['success']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{6,32}$/',$data['request_id']);
        $payload = $data['data'];
        foreach(['final_form','diff','token_usage','progress','progress_percent'] as $k) $this->assertArrayHasKey($k,$payload);
        $this->assertEquals('arshline_form@v1',$payload['final_form']['version']);
        $this->assertIsArray($payload['final_form']['fields']);
        $this->assertIsArray($payload['diff']);
        $this->assertIsArray($payload['token_usage']);
        $this->assertIsArray($payload['progress']);
        $this->assertIsFloat($payload['progress_percent']);
        $this->assertGreaterThan(0.0, $payload['progress_percent']);
        $this->assertLessThanOrEqual(1.0, $payload['progress_percent']);
        $expectedSeq = ['analyze_input','build_capabilities','openai_send','validate_response','persist_form','render_preview'];
        $this->assertEmpty(array_diff($expectedSeq,$payload['progress']));
        $this->assertEquals($expectedSeq, $payload['progress']); // strict ordering
    }
}
