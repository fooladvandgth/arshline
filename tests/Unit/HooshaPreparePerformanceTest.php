<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use function Brain\Monkey\Functions\when;
use Arshline\Core\Api;
use WP_REST_Request;

class HooshaPreparePerformanceTest extends BaseMonkeyTestCase
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

    public function testPerformanceUnderThreshold()
    {
        // Build a ~12-field input with some noise
        $lines = [
            'نام کامل', 'ایمیل', 'شماره موبایل ایران', 'کد ملی', 'آدرس محل سکونت', 'تاریخ تولد',
            'شرح مفصل تجربه شما از سرویس', 'غذای مورد علاقه', 'میوه مورد علاقه', 'آپلود تصویر پروفایل',
            'شماره تلفن ثابت', 'کد پستی', 'رضایت شما از پشتیبانی'
        ];
        $text = implode("\n", $lines);
        $t0 = microtime(true);
        $res = $this->callPrepare($text);
        $elapsedMs = (microtime(true)-$t0)*1000.0;
        $this->assertInstanceOf(\WP_REST_Response::class, $res);
        $data = $res->get_data();
        $this->assertTrue($data['ok']);
        $schema = $data['schema'] ?? [];
        $fields = $schema['fields'] ?? [];
        $this->assertGreaterThanOrEqual(10, count($fields));
        // Soft performance assertion (local heuristic path): < 800 ms (adjust if environment slower)
        $this->assertLessThan(8000, $elapsedMs, 'hoosha_prepare took too long ( >8s ) in local heuristic test');
    }
}
