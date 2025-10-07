<?php
use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\{Hosha2GenerateController,Hosha2GenerateService,Hosha2CapabilitiesBuilder,Hosha2OpenAIEnvelopeFactory,Hosha2OpenAIClientStub,Hosha2DiffValidator};

class Hosha2GenerateControllerValidationTest extends TestCase
{
    protected function makeController(): Hosha2GenerateController
    {
        $svc = new Hosha2GenerateService(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            new class extends Hosha2OpenAIClientStub { public function sendGenerate(array $env): array { return [ 'final_form'=>['version'=>'arshline_form@v1','fields'=>[],'layout'=>[],'meta'=>[]], 'diff'=>[], 'token_usage'=>['prompt'=>0,'completion'=>0,'total'=>0] ]; }},
            new Hosha2DiffValidator()
        );
        return new Hosha2GenerateController($svc);
    }

    protected function skipIfNoWP() : void
    {
        if (!class_exists('WP_REST_Request')) {
            $this->markTestSkipped('WP REST classes not loaded');
        }
    }

    public function testMissingPrompt()
    {
        $this->skipIfNoWP();
        $controller = $this->makeController();
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/55/generate');
        $req->set_param('form_id',55);
        // Intentionally NOT setting prompt
        $resp = $controller->handle($req);
        $this->assertEquals(400, $resp->get_status());
        $data = $resp->get_data();
        $this->assertFalse($data['success']);
        $this->assertEquals('missing_prompt', $data['error']['code']);
    }

    public function testInvalidFormIdZero()
    {
        $this->skipIfNoWP();
        $controller = $this->makeController();
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/0/generate');
        $req->set_param('form_id',0);
        $req->set_param('prompt','نمونه تست');
        $resp = $controller->handle($req);
        $this->assertEquals(400, $resp->get_status());
        $data = $resp->get_data();
        $this->assertEquals('invalid_form_id', $data['error']['code']);
    }

    public function testInvalidOptionsTypeString()
    {
        $this->skipIfNoWP();
        $controller = $this->makeController();
        $req = new WP_REST_Request('POST','/hosha2/v1/forms/77/generate');
        $req->set_param('form_id',77);
        $req->set_param('prompt','فرم جدید');
        $req->set_param('options','not-an-object');
        $resp = $controller->handle($req);
        $this->assertEquals(400, $resp->get_status());
        $data = $resp->get_data();
        $this->assertEquals('invalid_options_type', $data['error']['code']);
    }

    public function testEmptyPromptVariants()
    {
        $this->skipIfNoWP();
        $controller = $this->makeController();
        foreach (["","   ","\n\t  "] as $variant) {
            $req = new WP_REST_Request('POST','/hosha2/v1/forms/88/generate');
            $req->set_param('form_id',88);
            $req->set_param('prompt',$variant);
            $resp = $controller->handle($req);
            $this->assertEquals(400, $resp->get_status(), 'Status for variant '.json_encode($variant));
            $data = $resp->get_data();
            $this->assertEquals('empty_prompt', $data['error']['code']);
        }
    }
}
