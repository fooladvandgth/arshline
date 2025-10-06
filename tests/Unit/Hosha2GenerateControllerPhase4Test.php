<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\{Hosha2GenerateService,Hosha2CapabilitiesBuilder,Hosha2OpenAIEnvelopeFactory,Hosha2DiffValidator,Hosha2GenerateController,Hosha2VersionRepository,Hosha2FormRepositoryInterface,Hosha2OpenAIClientInterface,Hosha2LoggerInterface};

// Minimal shims if WP not loaded
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($arr,$err=false){ static $id=9500; $id++; $arr['ID']=$id; $arr['post_type']='hosha2_version'; global $hosha2_p4_posts; $hosha2_p4_posts[$id]=$arr; return $id; }
    function update_post_meta($id,$k,$v){ global $hosha2_p4_meta; $hosha2_p4_meta[$id][$k]=$v; }
    function is_wp_error($thing){ return false; }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request { private array $p=[]; public function __construct($m,$r){} public function set_param($k,$v){$this->p[$k]=$v;} public function get_param($k){return $this->p[$k]??null;} public function __get($k){return $this->p[$k]??null;} }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response { private $d; private $s; public function __construct($d,$s=200){$this->d=$d;$this->s=$s;} public function get_data(){return $this->d;} public function get_status(){return $this->s;} }
}

class P4MemoryLogger implements Hosha2LoggerInterface {
    public array $events=[]; public array $lastContext=[];
    public function log(string $event, array $payload = [], string $level = 'INFO'): void { $this->events[]=['event'=>$event,'payload'=>$payload,'level'=>$level]; }
    public function phase(string $phase, array $extra = [], string $level = 'INFO'): void { $this->log('phase:'.$phase,$extra,$level); }
    public function summary(array $metrics, array $issues = [], array $notes = []): void { $this->log('summary',['metrics'=>$metrics,'notes'=>$notes]); }
    public function setContext(array $context): void { $this->lastContext=$context; }
    public function rotateIfNeeded(): void { /* noop */ }
}

class Hosha2GenerateControllerPhase4Test extends TestCase
{
    private function makeController(array $opts): Hosha2GenerateController
    {
        $logger = new P4MemoryLogger();
        $client = $opts['client'];
        $formRepo = $opts['formRepo'] ?? null;
        $versionRepo = $opts['versionRepo'] ?? null;
        $service = new Hosha2GenerateService(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            $client,
            new Hosha2DiffValidator(),
            $logger,
            null,
            $versionRepo,
            $formRepo
        );
        $controller = new Hosha2GenerateController($service);
        // expose logger for assertions
        $controller->_p4_logger = $logger; // dynamic property ok in test context
        return $controller;
    }

    private function baseRequest(): WP_REST_Request
    {
        $r = new WP_REST_Request('POST','/hosha2/v1/forms/777/generate');
        $r->set_param('form_id',777);
        $r->set_param('prompt','فرم تست خطا');
        return $r;
    }

    // Test 13: OpenAI API failure -> 503 service_unavailable
    public function testPhase4_13_ServiceUnavailable()
    {
        $failingClient = new class implements Hosha2OpenAIClientInterface {
            public function sendGenerate(array $env): array { throw new RuntimeException('OPENAI_FAIL: network'); }
            public function sendValidate(array $env): array { return []; }
        };
        $controller = $this->makeController(['client'=>$failingClient]);
        $req = $this->baseRequest();
        $resp = $controller->handle($req);
        $this->assertSame(503,$resp->get_status(),'Should map OpenAI failure to 503');
        $data = $resp->get_data();
        $this->assertFalse($data['success']);
        $this->assertEquals('service_unavailable',$data['error']['code']);
        $this->assertStringContainsString('AI service',$data['error']['message']);
    }

    // Test 14: Form repository returns null -> 404 form_not_found
    public function testPhase4_14_FormNotFound()
    {
        $client = new class implements Hosha2OpenAIClientInterface { public function sendGenerate(array $env): array { return ['final_form'=>['version'=>'arshline_form@v1','fields'=>[],'layout'=>[],'meta'=>[]],'diff'=>[],'token_usage'=>['prompt'=>1,'completion'=>1,'total'=>2]]; } public function sendValidate(array $env): array { return []; } };
        $formRepo = new class implements Hosha2FormRepositoryInterface { public function findById(int $id): ?array { return null; } };
        $controller = $this->makeController(['client'=>$client,'formRepo'=>$formRepo]);
        $req = $this->baseRequest();
        $resp = $controller->handle($req);
        $this->assertSame(404,$resp->get_status());
        $data=$resp->get_data();
        $this->assertEquals('form_not_found',$data['error']['code']);
    }

    // Test 15: Form repository throws -> 500 internal_error + log
    public function testPhase4_15_FormRepoDbError()
    {
        $client = new class implements Hosha2OpenAIClientInterface { public function sendGenerate(array $env): array { return ['final_form'=>['version'=>'arshline_form@v1','fields'=>[],'layout'=>[],'meta'=>[]],'diff'=>[],'token_usage'=>['prompt'=>1,'completion'=>1,'total'=>2]]; } public function sendValidate(array $env): array { return []; } };
        $formRepo = new class implements Hosha2FormRepositoryInterface { public function findById(int $id): ?array { throw new RuntimeException('DB_BROKEN'); } };
        $controller = $this->makeController(['client'=>$client,'formRepo'=>$formRepo]);
        $req = $this->baseRequest();
        $resp = $controller->handle($req);
        $this->assertSame(500,$resp->get_status());
        $data=$resp->get_data();
        $this->assertEquals('internal_error',$data['error']['code']);
        // logger should have recorded something (runtime error path -> not guaranteed event name, just ensure logger context set)
        $this->assertNotEmpty($controller->_p4_logger->lastContext);
    }

    // Test 16: Version save fails but request still succeeds
    public function testPhase4_16_VersionSaveDegradesGracefully()
    {
        $client = new class implements Hosha2OpenAIClientInterface { public function sendGenerate(array $env): array { return ['final_form'=>['version'=>'arshline_form@v1','fields'=>[['id'=>'f1','type'=>'text']],'layout'=>[],'meta'=>[]],'diff'=>[['op'=>'add','path'=>'/fields/0','value'=>['id'=>'f1','type'=>'text']]],'token_usage'=>['prompt'=>3,'completion'=>5,'total'=>8]]; } public function sendValidate(array $env): array { return []; } };
        $badVersionRepo = new class extends Hosha2VersionRepository { public function __construct(){ parent::__construct(null); } public function saveSnapshot(int $formId, array $config, array $metadata = [], ?string $diffSha = null): int { throw new RuntimeException('STORAGE_DOWN'); } };
        $controller = $this->makeController(['client'=>$client,'versionRepo'=>$badVersionRepo]);
        $req = $this->baseRequest();
        $resp = $controller->handle($req);
        $this->assertSame(200,$resp->get_status(),'Generation should succeed even if version save fails');
        $data=$resp->get_data();
        $this->assertTrue($data['success']);
        $this->assertNull($data['version_id'],'version_id must be null when save fails');
        $this->assertNull($data['diff_sha'],'diff_sha must be null when persistence failed (cleared)');
    }
}
