<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use function Brain\Monkey\Functions\when;
use Arshline\Core\Api;
use WP_REST_Request;

class HooshaPrepareLargeFormTest extends BaseMonkeyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    when('current_user_can')->justReturn(true);
    when('get_current_user_id')->justReturn(1);
    when('get_option')->alias(fn($k)=>null);
    }
    // tearDown inherited

    private function callPrepare(string $text)
    {
        $req = new WP_REST_Request('POST','/arshline/v1/hoosha/prepare');
        $req->set_body(json_encode(['user_text'=>$text], JSON_UNESCAPED_UNICODE));
        return Api::hoosha_prepare($req);
    }

    public function testLargeFormMayIncludeContactPreference()
    {
        $text = "نام کامل\nایمیل\nشماره موبایل\nکد ملی\nآدرس\nتاریخ تولد\nتجربه شما از سرویس\nغذای مورد علاقه\nشرح مشکل\nنوع ارتباط دلخواه (ایمیل یا تلفن یا موبایل)";
        $res = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $res);
        $data = $res->get_data();
        $schema = $data['schema'] ?? [];
        $fields = $schema['fields'] ?? [];
        $labels = array_map(fn($f)=>$f['label']??'', $fields);
        $hasContact = false;
        foreach ($labels as $l){ if (mb_strpos($l,'ترجیح')!==false && mb_strpos($l,'تماس')!==false){ $hasContact=true; break; } }
        // در فرم بزرگ نبودنش خطا نیست، ولی بودنش مجاز باید باشد.
        $this->assertGreaterThanOrEqual(8, count($fields));
        // اگر هست، نوع باید multiple_choice یا dropdown باشد.
        if ($hasContact){
            $okType=false; foreach ($fields as $f){ if (isset($f['label']) && mb_strpos($f['label'],'ترجیح')!==false){ $t=$f['type']??''; if (in_array($t,['multiple_choice','dropdown'],true)) $okType=true; } }
            $this->assertTrue($okType,'Contact preference should be a choice-based field');
        }
    }
}
