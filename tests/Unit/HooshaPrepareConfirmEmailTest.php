<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Arshline\Core\Api;
use WP_REST_Request;

class HooshaPrepareConfirmEmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        Functions::when('current_user_can')->justReturn(true);
        Functions::when('get_current_user_id')->justReturn(1);
        Functions::when('get_option')->alias(fn($k)=>null);
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

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
