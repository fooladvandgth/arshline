<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Arshline\Hosha2\{Hosha2GenerateService,Hosha2CapabilitiesBuilder,Hosha2OpenAIEnvelopeFactory,Hosha2OpenAIClientStub,Hosha2DiffValidator};

/**
 * Tests cancellation checkpoints in Hosha2GenerateService.
 * We simulate WordPress transients if WP functions are not available.
 */
class Hosha2CancellationTest extends TestCase
{
    protected function ensureTransientShims(): void
    {
        if (!function_exists('get_transient')) {
            if (!defined('HOSHA2_TRANSIENT_SHIM')) {
                define('HOSHA2_TRANSIENT_SHIM', true);
                global $hosha2_test_transients; $hosha2_test_transients = [];
                eval('function set_transient($key,$value,$ttl){ global $hosha2_test_transients; $hosha2_test_transients[$key]=["v"=>$value,"exp"=>time()+$ttl]; return true; }');
                eval('function get_transient($key){ global $hosha2_test_transients; if(!isset($hosha2_test_transients[$key])) return false; if($hosha2_test_transients[$key]["exp"]<time()) return false; return $hosha2_test_transients[$key]["v"]; }');
            }
        }
    }

    protected function makeService(): Hosha2GenerateService
    {
        return new Hosha2GenerateService(
            new Hosha2CapabilitiesBuilder(),
            new Hosha2OpenAIEnvelopeFactory(),
            new Hosha2OpenAIClientStub(),
            new Hosha2DiffValidator()
        );
    }

    public function testCancelBeforeCapabilities()
    {
        $this->ensureTransientShims();
        $service = $this->makeService();
        $req = 'reqA';
        set_transient('hosha2_cancel_' . $req, 1, 300);
        $result = $service->generate(['req_id'=>$req,'prompt'=>'X']);
        $this->assertTrue($result['cancelled']);
        $this->assertEquals($req, $result['request_id']);
    }

    public function testCancelBeforeOpenAI()
    {
        $this->ensureTransientShims();
        $service = $this->makeService();
        $req = 'reqB';
        // Will cancel after capabilities: emulate by not setting until after first generate step.
        $result1 = $service->generate(['req_id'=>$req,'prompt'=>'seed']);
        // first run completes (not cancelled)
        $this->assertArrayNotHasKey('cancelled', $result1);
        // Now cancel and call again; we need to intercept before openai -> set transient right before second run
        set_transient('hosha2_cancel_' . $req, 1, 300);
        $result2 = $service->generate(['req_id'=>$req,'prompt'=>'seed2']);
        $this->assertTrue($result2['cancelled']);
    }

    public function testCancelAfterOpenAI()
    {
        $this->ensureTransientShims();
        // Create a custom client stub that sets the cancellation flag AFTER returning from OpenAI mock.
        $req = 'reqC';
        $client = new class extends \Arshline\Hosha2\Hosha2OpenAIClientStub {
            public string $reqId;
            public function sendGenerate(array $envelope): array {
                $res = parent::sendGenerate($envelope);
                if (function_exists('set_transient')) set_transient('hosha2_cancel_' . $this->reqId, 1, 300);
                return $res;
            }
        };
        $client->reqId = $req;
        $service = new \Arshline\Hosha2\Hosha2GenerateService(
            new \Arshline\Hosha2\Hosha2CapabilitiesBuilder(),
            new \Arshline\Hosha2\Hosha2OpenAIEnvelopeFactory(),
            $client,
            new \Arshline\Hosha2\Hosha2DiffValidator()
        );
        $result = $service->generate(['req_id'=>$req,'prompt'=>'after-openai']);
        $this->assertTrue($result['cancelled'], 'Expected cancellation after OpenAI stage');
    }

    public function testNoCancellation()
    {
        $this->ensureTransientShims();
        $service = $this->makeService();
        $res = $service->generate(['req_id'=>'reqD','prompt'=>'normal']);
        $this->assertArrayHasKey('final_form', $res);
        $this->assertArrayNotHasKey('cancelled', $res);
    }

    public function testHelperFunction()
    {
        $this->ensureTransientShims();
        if (!function_exists('hosha2_cancel_request')) require_once __DIR__ . '/../../src/Hosha2/bootstrap.php';
        $req = 'reqE';
        hosha2_cancel_request($req, 200);
        $this->assertTrue((bool) get_transient('hosha2_cancel_' . $req));
    }
}
?>
