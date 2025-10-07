<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use function Brain\Monkey\Functions\when;
use Arshline\Core\Api;
use WP_REST_Request;

class HooshaPrepareConfirmEmailTest extends BaseMonkeyTestCase
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

    public function testEmailConfirmLinking()
    {
        $text = "ایمیل\nایمیلت رو دوباره وارد کن";
        $res = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $res);
        $data = $res->get_data();
        $fields = $data['schema']['fields'] ?? [];
        $emailIndexes=[]; foreach ($fields as $i=>$f){ if(($f['props']['format']??'')==='email') $emailIndexes[]=$i; }
        $this->assertGreaterThanOrEqual(1, count($emailIndexes));
        if (count($emailIndexes)>=2){
            $second = $fields[$emailIndexes[1]];
            $this->assertNotEmpty($second['props']['confirm_for'] ?? null, 'Second email field should reference confirm_for');
        }
    }
}
