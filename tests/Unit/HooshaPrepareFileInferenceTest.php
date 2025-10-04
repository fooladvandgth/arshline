<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Arshline\Core\Api;
use WP_REST_Request;

class HooshaPrepareFileInferenceTest extends TestCase
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

    public function testImageTurnsIntoFileField()
    {
        $text = "نام کامل\nکد ملی\nلطفاً تصویر رسید پرداخت را آپلود کن";
        $res = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $res);
        $data = $res->get_data();
        $schema = $data['schema'] ?? [];
        $fields = $schema['fields'] ?? [];
        $this->assertNotEmpty($fields);
        $foundFile=false; $accept=[];
        foreach ($fields as $f){
            if (($f['type']??'')==='file'){ $foundFile=true; $accept=$f['props']['accept']??[]; }
        }
        $this->assertTrue($foundFile,'Expected a file field inferred from image upload instruction');
        $this->assertNotEmpty($accept,'Accept list should not be empty');
        $this->assertContains('image/jpeg',$accept);
        $this->assertContains('image/png',$accept);
    }
}
