<?php
namespace Arshline\Tests\Unit;

use Arshline\Hosha2\{Hosha2CapabilitiesBuilder,Hosha2OpenAIEnvelopeFactory,Hosha2OpenAIClientStub,Hosha2DiffValidator,Hosha2GenerateService,Hosha2ProgressTracker};
use PHPUnit\Framework\TestCase;

class Hosha2F3ComponentsTest extends TestCase
{
    public function testClientStubGenerate()
    {
        $client = new Hosha2OpenAIClientStub();
        $envFactory = new Hosha2OpenAIEnvelopeFactory();
        $builder = new Hosha2CapabilitiesBuilder();
        $cap = $builder->build(true);
        $env = $envFactory->createGenerate(['prompt'=>'ساخت فرم تست'], $cap, []);
        $res = $client->sendGenerate($env);
        $this->assertEquals('generate', $res['intent']);
        $this->assertNotEmpty($res['final_form']['fields']);
        $this->assertGreaterThan(0, $res['token_usage']['total']);
    }

    public function testProgressTrackerAccumulation()
    {
        $tracker = new Hosha2ProgressTracker('req123');
        $tracker->mark('analyze_input');
        $tracker->mark('build_capabilities');
        $tracker->mark('openai_send');
        $this->assertGreaterThan(0.3, $tracker->progress());
    }

    public function testDiffValidatorInvalid()
    {
        $validator = new Hosha2DiffValidator();
        $ok = $validator->validate([
            ['op'=>'add','path'=>'/fields/0','value'=>['id'=>'f1']],
            ['path'=>'/fields/1'],
        ]);
        $this->assertFalse($ok);
        $this->assertNotEmpty($validator->errors());
    }

    public function testGenerateServicePipeline()
    {
        $service = new Hosha2GenerateService(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            new Hosha2OpenAIClientStub(),
            new Hosha2DiffValidator()
        );
        $result = $service->generate(['prompt'=>'فرم ثبت نام']);
        $this->assertArrayHasKey('final_form', $result);
        $this->assertArrayHasKey('diff', $result);
        $this->assertArrayHasKey('token_usage', $result);
        $this->assertEquals(1.0, $result['progress']);
    }
}
?>