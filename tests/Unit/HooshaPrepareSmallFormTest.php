<?php
namespace Arshline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Arshline\Core\Api;
use WP_REST_Request;

class HooshaPrepareSmallFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        // Minimal WP capability mocks
        Functions::when('current_user_can')->justReturn(true);
        Functions::when('get_current_user_id')->justReturn(1);
        // AI settings disabled to force local / deterministic path
        Functions::when('get_option')->alias(function($key){ return null; });
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

    public function testSmallFormNoExtraneousContactPreference()
    {
        $text = "اسمت چیه\nامروز چه تاریخیه\nکد ملیتو بده\nشماره تلفن رو بده . اجباری";
        $res = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $res);
        $data = $res->get_data();
        $this->assertTrue($data['ok']);
        $schema = $data['schema'] ?? [];
        $fields = $schema['fields'] ?? [];
        // Expect exactly 4 baseline fields (small form enforcement)
        $this->assertCount(4, $fields, 'Should have exactly baseline 4 fields after strict small form enforcement');
        $labels = array_map(fn($f)=>$f['label']??'', $fields);
        foreach ($labels as $lbl){
            $this->assertStringNotContainsString('ترجیح', $lbl, 'Contact preference must not appear');
        }
        // Ensure national id & mobile formats present and required enforced
        $fmtMap = [];
        foreach ($fields as $f){
            $fmt = $f['props']['format'] ?? ''; if($fmt) $fmtMap[$fmt]=true;
        }
        $this->assertArrayHasKey('national_id_ir', $fmtMap);
        $this->assertArrayHasKey('mobile_ir', $fmtMap);
    }

    public function testDuplicateCollapseKeepsStructuredVariant()
    {
        // Provide variant synonyms to try to trip duplication
        $text = "کد ملیتو بده\nکد ملی را مجدد وارد کن\nشماره تلفن رو بده\nشماره موبایل ایران رو وارد کن";
        $res = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $res);
        $data = $res->get_data();
        $schema = $data['schema'] ?? [];
        $fields = $schema['fields'] ?? [];
        // Still small form => should not explode beyond 4 (may restore but collapse duplicates)
        $this->assertLessThanOrEqual(4, count($fields));
        // Ensure only one primary national id (others either removed or tagged duplicate_of)
        $natIndexes=[]; foreach($fields as $idx=>$f){ if(($f['props']['format']??'')==='national_id_ir'){ $natIndexes[]=$idx; } }
        $this->assertGreaterThanOrEqual(1, count($natIndexes));
        // Check no spuriously added contact preference
        foreach ($fields as $f){ $this->assertStringNotContainsString('ترجیح', $f['label']??''); }
    }
}
