<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use WP_REST_Request;
use Arshline\Core\Api;
use function Brain\Monkey\Functions\when;

class GuardDuplicateCollapseTest extends BaseMonkeyTestCase
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
            ];
            return $d; });
    }

    private function callPrepare(string $text)
    {
        $req = new WP_REST_Request('POST','/arshline/v1/hoosha/prepare');
        $req->set_body(json_encode(['user_text'=>$text], JSON_UNESCAPED_UNICODE));
        return Api::hoosha_prepare($req);
    }

    public function testSemanticDuplicatesCollapsed()
    {
        $text = "کد ملی\nکد ملی ایران\nکدملی شما\nشماره موبایل\nشماره تلفن همراه"; // duplicates for national id & mobile
        $resp = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        $fields = $data['schema']['fields'] ?? [];
        $labels = array_map(fn($f)=>$f['label']??'', $fields);
        // Must not retain more than one national id nor more than one mobile variant
        $nat = preg_grep('/کد\s*ملی/u', $labels);
        $this->assertLessThanOrEqual(1, count($nat), 'National ID duplicates not collapsed');
        $mob = preg_grep('/موبایل|تلفن همراه/u', $labels);
        $this->assertLessThanOrEqual(1, count($mob), 'Mobile duplicates not collapsed');
        $notes = $data['notes'] ?? [];
        $this->assertTrue((bool)preg_grep('/guard:duplicate_collapsed/',$notes), 'Missing duplicate collapse guard note');
    }
}
