<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Arshline\Core\Api;
use WP_REST_Request;

class HooshaPrepareEmptyInputTest extends TestCase
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
