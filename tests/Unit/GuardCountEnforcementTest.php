<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use WP_REST_Request;
use Arshline\Core\Api;
use function Brain\Monkey\Functions\when;

/**
 * GuardCountEnforcementTest
 * Ensures that when ai_guard_enabled=true and allow_ai_additions disabled,
 * any AI-added hallucinated fields beyond baseline count are trimmed and notes record enforcement.
 */
class GuardCountEnforcementTest extends BaseMonkeyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        when('current_user_can')->justReturn(true);
        when('get_current_user_id')->justReturn(1);
        // Enable guard, disallow additions
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

    public function testExcessFieldsTrimmedToBaseline()
    {
        // Baseline should infer 4 fields from these lines; add extra distracting lines to attempt AI additions
        $text = "نام شما\nکد ملی شما\nشماره موبایل\nتوضیح مشکل\n(مدل شاید اضافه کند) علاقه مندی ها\n(مدل شاید اضافه کند) ترجیحات ارتباط";
        $resp = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        $this->assertTrue($data['ok']);
        $schema = $data['schema'] ?? [];
        $fields = $schema['fields'] ?? [];
        // Expect trimmed to baseline (<=4)
        $this->assertLessThanOrEqual(4, count($fields), 'Guard must trim to baseline count when additions disallowed');
        $notes = $data['notes'] ?? [];
        // Should include enforced count limit or ai_removed notes when pruning occurs
        $this->assertTrue((bool)preg_grep('/guard:enforced_count_limit/',$notes) || (bool)preg_grep('/guard:ai_removed/',$notes), 'Expected guard enforcement notes not present');
    }
}
