<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use function Brain\Monkey\Functions\when;
use Arshline\Core\Api;
use WP_REST_Request;

/**
 * Ensures graceful fallback path activates when model repeatedly fails (returns invalid JSON / error).
 */
class HooshaPrepareModelFailureFallbackTest extends BaseMonkeyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    when('current_user_can')->justReturn(true);
    when('get_current_user_id')->justReturn(1);
        // Provide AI config so code attempts model call
    when('get_option')->alias(function($key){
            if ($key==='arshline_settings'){
                return [ 'ai_enabled'=>true, 'ai_base_url'=>'https://fake.local', 'ai_api_key'=>'x' ];
            }
            return null;
        });
        // Simulate model failure (HTTP 500) for all attempts
    when('wp_remote_post')->alias(function($endpoint,$args){
            return [ 'response'=>['code'=>500], 'body'=>'{"error":"upstream"}' ];
        });
    when('wp_remote_retrieve_response_code')->alias(fn($r)=> $r['response']['code'] ?? 0);
    when('wp_remote_retrieve_body')->alias(fn($r)=> $r['body'] ?? '');
    when('is_wp_error')->alias(fn($v)=> false);
    }

    // tearDown inherited

    private function callPrepare(string $text)
    {
        $req = new WP_REST_Request('POST','/arshline/v1/hoosha/prepare');
        $req->set_body(json_encode(['user_text'=>$text], JSON_UNESCAPED_UNICODE));
        return Api::hoosha_prepare($req);
    }

    public function testModelFailureFallsBackToBaseline()
    {
        $text = "نام کامل\nایمیل\nشماره موبایل";
        $res = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $res, 'Expected response fallback');
        $data = $res->get_data();
        $this->assertTrue($data['ok'] ?? false);
        $notes = $data['notes'] ?? [];
        // Expect fallback markers
        $hasModelFail = false; $hasFallback=false;
        foreach ($notes as $n){
            if (strpos($n,'pipe:model_call_failed')!==false) $hasModelFail=true;
            if (strpos($n,'pipe:fallback_from_model_failure')!==false) $hasFallback=true;
        }
        $this->assertTrue($hasModelFail, 'Missing model_call_failed note');
        $this->assertTrue($hasFallback, 'Missing fallback_from_model_failure note');
        $schema = $data['schema'] ?? [];
        $fields = $schema['fields'] ?? [];
        $this->assertNotEmpty($fields, 'Fallback schema should not be empty');
    }
}
