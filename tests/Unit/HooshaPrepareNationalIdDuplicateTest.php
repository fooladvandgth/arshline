<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Arshline\Core\Api;
use WP_REST_Request;

class HooshaPrepareNationalIdDuplicateTest extends TestCase
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

    public function testNationalIdDuplicateTaggedOrCollapsed()
    {
        $text = "کد ملی\nکد ملی را دوباره وارد کن";
        $res = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $res);
        $data = $res->get_data();
        $fields = $data['schema']['fields'] ?? [];
        $natIndices=[]; foreach ($fields as $i=>$f){ if(($f['props']['format']??'')==='national_id_ir') $natIndices[]=$i; }
        $this->assertGreaterThanOrEqual(1, count($natIndices));
        if (count($natIndices)>=2){
            // Expect confirm_for or duplicate_of tagging
            $tagged = false; foreach($natIndices as $idx){ $f=$fields[$idx]; if(!empty($f['props']['confirm_for']) || !empty($f['props']['duplicate_of'])) $tagged=true; }
            $this->assertTrue($tagged,'One of duplicate national id fields should be tagged confirm_for or duplicate_of');
        }
    }
}
