<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use WP_REST_Request;
use Arshline\Core\Api;
// TEMP: explicit require to diagnose autoload issue in CI environment
if (!class_exists(\Arshline\Core\Api::class)) {
    require_once __DIR__ . '/../../src/Core/Api.php';
}
use function Brain\Monkey\Functions\when;

class HooshaPrepareBaselinePreservationTest extends BaseMonkeyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        when('get_current_user_id')->justReturn(1);
        when('current_user_can')->justReturn(true);
        // AI settings – pretend configured
        when('get_option')->alias(function($k,$d=null){
            if ($k==='arshline_settings') return ['ai_final_review_enabled'=>false];
            if ($k==='arshline_ai_settings') return ['enabled'=>true,'base_url'=>'http://invalid.local','api_key'=>'test','model'=>'gpt-4o-mini'];
            return $d; });
        // Short-circuit network calls so model path fails and baseline fallback logic executes; this exercises reconciliation + audit.
        when('wp_remote_post')->justReturn(['response'=>['code'=>500],'body'=>'{}']);
        when('wp_remote_retrieve_response_code')->alias(function($r){ return $r['response']['code']??500; });
        when('wp_remote_retrieve_body')->alias(function($r){ return $r['body']??''; });
        when('is_wp_error')->justReturn(false);
    }
    // tearDown inherited

    public function testBaselineFieldsNotDropped()
    {
        $userText = "نام شما\nکد ملی شما\nشماره موبایل\nتوضیح کامل مشکل"; // 4 baseline fields -> strict small form mode
        $req = new WP_REST_Request('POST','/arshline/v1/hoosha/prepare');
        $req->set_body(json_encode(['user_text'=>$userText], JSON_UNESCAPED_UNICODE));
        $resp = Api::hoosha_prepare($req);
        $this->assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        $this->assertTrue($data['ok']);
        $this->assertNotEmpty($data['schema']['fields']);
        $labels = array_map(fn($f)=>$f['label'], $data['schema']['fields']);
        // Ensure each original concept present (allow formalized variants)
        $expectCanon = ['نام','کد ملی','شماره موبایل','توضیح کامل مشکل'];
        $canonSeen = [];
        foreach ($labels as $l){ $canonSeen[] = preg_replace('/\s+/u','', $l); }
        $joined = implode('|',$canonSeen);
        foreach ($expectCanon as $exp){
            $this->assertMatchesRegularExpression('/'.preg_quote(preg_replace('/\s+/u','', $exp),'/').'/u', $joined, 'Missing baseline field '.$exp);
        }
        // Audit notes should include baseline or coverage indicators
        $notes = $data['notes'] ?? [];
        $this->assertNotEmpty($notes);
        $this->assertTrue((bool)preg_grep('/audit:coverage/',$notes), 'Missing audit:coverage note');
    }
}
