<?php
namespace Arshline\Tests\Unit;

use function Brain\Monkey\Functions\when;
use Arshline\Core\Api;
use WP_REST_Request;
use Arshline\Tests\Unit\BaseMonkeyTestCase;

class HooshaPrepareEmptyInputTest extends BaseMonkeyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    when('current_user_can')->justReturn(true);
    when('get_current_user_id')->justReturn(1);
    when('get_option')->alias(fn($k)=>null);
    }

    public function testEmptyUserTextReturns400()
    {
        $req = new WP_REST_Request('POST','/arshline/v1/hoosha/prepare');
        $req->set_body(json_encode(['user_text'=>''], JSON_UNESCAPED_UNICODE));
        $res = Api::hoosha_prepare($req);
        $this->assertInstanceOf(\WP_Error::class, $res, 'Expected WP_Error for empty user_text');
        $this->assertEquals(400, $res->get_error_data()['status']);
    }

    public function testWhitespaceUserTextReturns400()
    {
        $req = new WP_REST_Request('POST','/arshline/v1/hoosha/prepare');
        $req->set_body(json_encode(['user_text'=>'   '], JSON_UNESCAPED_UNICODE));
        $res = Api::hoosha_prepare($req);
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertEquals(400, $res->get_error_data()['status']);
    }
}
