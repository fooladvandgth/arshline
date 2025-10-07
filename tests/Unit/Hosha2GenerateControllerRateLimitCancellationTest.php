<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\{Hosha2GenerateController,Hosha2GenerateService,Hosha2CapabilitiesBuilder,Hosha2OpenAIEnvelopeFactory,Hosha2OpenAIClientStub,Hosha2DiffValidator,Hosha2RateLimiter,Hosha2VersionRepository};

class Hosha2GenerateControllerRateLimitCancellationTest extends TestCase
{
    protected function skipIfNoWP(): void
    {
        if (!class_exists('WP_REST_Request')) {
            $this->markTestSkipped('WP REST classes not loaded');
        }
    }

    protected function baseService(?Hosha2RateLimiter $limiter = null): Hosha2GenerateService
    {
        $client = new class extends Hosha2OpenAIClientStub { public function sendGenerate(array $env): array { return [ 'final_form'=>['version'=>'arshline_form@v1','fields'=>[],'layout'=>[],'meta'=>[]], 'diff'=>[], 'token_usage'=>['prompt'=>0,'completion'=>0,'total'=>0] ]; }};
        return new Hosha2GenerateService(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            $client,
            new Hosha2DiffValidator(),
            null,
            $limiter,
            null
        );
    }

    public function testRateLimitExceededReturns429()
    {
        $this->skipIfNoWP();
        // Limiter stub that always disallows new requests
        $limiter = new class extends Hosha2RateLimiter {
            public function __construct(){ /* bypass parent */ }
            public function isAllowed(string $r): bool { return false; }
            public function recordRequest(string $r): void { /* noop */ }
        };
        $controller = new Hosha2GenerateController($this->baseService($limiter));
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/101/generate');
        $req->set_param('form_id',101);
        $req->set_param('prompt','ساخت فرم');
        $resp = $controller->handle($req);
        $this->assertEquals(429, $resp->get_status());
        $data = $resp->get_data();
        $this->assertFalse($data['success']);
        $this->assertEquals('rate_limited', $data['error']['code']);
    }

    public function testCancellationBeforeCapabilitiesReturns499()
    {
        $this->skipIfNoWP();
        $service = $this->baseService();
        $controller = new Hosha2GenerateController($service);
        $reqId = 'abc123aa';
        if (function_exists('set_transient')) {
            set_transient('hosha2_cancel_' . $reqId, true, 30);
        }
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/102/generate');
        $req->set_param('form_id',102);
        $req->set_param('prompt','فرم جدید');
        $req->set_param('req_id',$reqId);
        $resp = $controller->handle($req);
        $this->assertEquals(499, $resp->get_status());
        $data = $resp->get_data();
        $this->assertFalse($data['success']);
        $this->assertTrue($data['cancelled']);
        $this->assertEquals('request_cancelled', $data['error']['code']);
        $this->assertEquals($reqId, $data['request_id']);
    }

    public function testCancellationAfterOpenAIBeforeValidationReturns499()
    {
        $this->skipIfNoWP();
        $client = new class extends Hosha2OpenAIClientStub { public function sendGenerate(array $env): array { return [ 'final_form'=>['version'=>'arshline_form@v1','fields'=>[],'layout'=>[],'meta'=>[]], 'diff'=>[], 'token_usage'=>['prompt'=>0,'completion'=>0,'total'=>0] ]; } };
        $service = new class(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            $client,
            new Hosha2DiffValidator()
        ) extends Hosha2GenerateService {
            public function generate(array $userInput): array {
                $reqId = $userInput['req_id'];
                // Simulate performing all steps up to and including OpenAI call, then set cancel flag to trigger controller cancellation path by returning cancelled structure directly.
                if (function_exists('set_transient')) set_transient('hosha2_cancel_'.$reqId, true, 30);
                return ['request_id'=>$reqId,'cancelled'=>true,'message'=>'Request cancelled by user'];
            }
        };
        $controller = new Hosha2GenerateController($service);
        $reqId = 'def456bb';
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/103/generate');
        $req->set_param('form_id',103);
        $req->set_param('prompt','فرم با فیلدها');
        $req->set_param('req_id',$reqId);
        $resp = $controller->handle($req);
        $this->assertEquals(499, $resp->get_status());
        $data = $resp->get_data();
        $this->assertFalse($data['success']);
        $this->assertTrue($data['cancelled']);
        $this->assertEquals('request_cancelled', $data['error']['code']);
    }
}
