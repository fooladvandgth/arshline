<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use WP_REST_Request;
use Arshline\Core\Api;
use function Brain\Monkey\Functions\when;

class GuardTypeUnifyTest extends BaseMonkeyTestCase
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

    public function testTypeUnifiedForSameLabel()
    {
        // Provide same concept expressed with commas to trigger multiple_choice vs short_text variants
        $text = "علایق: فوتبال، بسکتبال، تنیس\nعلایق شما چیست";
        $resp = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        $fields = $data['schema']['fields'] ?? [];
        $canon = [];
        foreach ($fields as $f){
            $lbl = $f['label']??''; if ($lbl==='') continue;
            $c = preg_replace('/\s+/u','', mb_strtolower($lbl,'UTF-8'));
            $canon[$c][] = $f['type'] ?? '';
        }
        $unifiedOk = true;
        foreach ($canon as $types){ if (count(array_unique($types))>1){ $unifiedOk=false; break; } }
        $this->assertTrue($unifiedOk, 'Expected type_unified across canonical labels');
        $notes = $data['notes'] ?? [];
        $this->assertTrue((bool)preg_grep('/guard:type_unified/',$notes), 'Missing guard:type_unified note');
    }
}
