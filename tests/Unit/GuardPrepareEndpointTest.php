<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use WP_REST_Request;
use Arshline\Core\Api;
use function Brain\Monkey\Functions\when;

class GuardPrepareEndpointTest extends BaseMonkeyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        when('current_user_can')->justReturn(true);
        when('get_current_user_id')->justReturn(1);
        when('get_option')->alias(function($k,$d=null){
            if ($k==='arshline_settings') return [
                'ai_guard_enabled'=>true,
                'allow_ai_additions'=>false,
                'ai_enabled'=>true,
                'ai_api_key'=>'test',
                'ai_base_url'=>'http://invalid.local'
            ];
            if ($k==='arshline_use_model') return '1';
            return $d; });
        when('wp_remote_post')->justReturn(['response'=>['code'=>500],'body'=>'{}']);
        when('wp_remote_retrieve_response_code')->alias(function($r){ return $r['response']['code']??500; });
        when('wp_remote_retrieve_body')->alias(function($r){ return $r['body']??''; });
        when('is_wp_error')->justReturn(false);
    }

    private function callPrepare(string $text)
    {
        $req = new WP_REST_Request('POST','/arshline/v1/hoosha/prepare');
        $req->set_body(json_encode(['user_text'=>$text], JSON_UNESCAPED_UNICODE));
        return Api::hoosha_prepare($req);
    }

    public function testNoAuditRestoredWhenGuardActive()
    {
        $text = "تاریخ تولد خود را چه زمانی است؟\nروز تولدتان را وارد کنید.\nکد ملی خود را بنویسید.\nشماره ملی را ثبت کنید.\nدر هفته چند روز ورزش می‌کنید؟\nچند روز در هفته فعالیت ورزشی دارید؟\nرنگ مورد علاقه‌تان چیست؟\nرنگ محبوب خود را انتخاب کنید: سبز، آبی، قرمز";
        $resp = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        $notes = $data['notes'] ?? [];
        $this->assertFalse((bool)preg_grep('/audit:restored/',$notes), 'audit:restored should be suppressed under guard');
        // Guard block should exist
        $this->assertArrayHasKey('guard', $data, 'Guard block missing');
        // No Jalali hallucination if not in input
        $labels = array_map(fn($f)=>$f['label']??'', $data['schema']['fields']??[]);
        $this->assertFalse((bool)preg_grep('/تاریخ \(جلالی\)/u',$labels), 'Hallucinated Jalali date should be pruned');
    }
}
