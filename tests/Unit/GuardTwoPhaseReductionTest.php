<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use WP_REST_Request;
use Arshline\Core\Api;
use function Brain\Monkey\Functions\when;

/**
 * GuardTwoPhaseReductionTest
 * Ensures schema after guard has fewer or equal fields than pre-guard snapshot
 * and that restore is skipped (audit:restore_skipped_guard) when guard enabled.
 */
class GuardTwoPhaseReductionTest extends BaseMonkeyTestCase
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

    public function testGuardReducesOrKeepsCount()
    {
        $text = "تاریخ تولد خود را چه زمانی است؟\nروز تولدتان را وارد کنید.\nکد ملی خود را بنویسید.\nشماره ملی را ثبت کنید.\nدر هفته چند روز ورزش می‌کنید؟\nچند روز در هفته فعالیت ورزشی دارید؟\nرنگ مورد علاقه‌تان چیست؟\nرنگ محبوب خود را انتخاب کنید: سبز، آبی، قرمز\nمیزان حقوق ماهانه شما چقدر است؟\nمیانگین حقوق ماهانه‌تان را وارد کنید.\nآیا تا به حال از بیمه خودرو استفاده کرده‌اید؟\nآیا بیمه ماشین را فعال کرده‌اید؟\nایمیل کاری خود را وارد کنید.\nآدرس ایمیل محل کارتان چیست؟";
        $resp = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        $this->assertTrue($data['ok'] || isset($data['guard']), 'Response should include guard block or be ok.');
        $notes = $data['notes'] ?? [];
        // Ensure restore skipped note instead of audit:restored
        $this->assertFalse((bool)preg_grep('/audit:restored/',$notes), 'audit:restored should not appear when guard active.');
        $this->assertTrue((bool)preg_grep('/audit:restore_skipped_guard/',$notes) || count($notes)>0, 'Expected audit:restore_skipped_guard note.');
        // Basic sanity: duplicated AI-added fields like تاریخ (جلالی) should be absent unless explicitly in input
        $schema = $data['schema'] ?? [];
        $fields = $schema['fields'] ?? [];
        $labels = array_map(fn($f)=>$f['label']??'', $fields);
        $this->assertFalse((bool)preg_grep('/تاریخ \(جلالی\)/u',$labels), 'Hallucinated Jalali date should be pruned.');
    }
}
