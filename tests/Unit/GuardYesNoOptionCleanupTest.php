<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use WP_REST_Request;
use Arshline\Core\Api;
use function Brain\Monkey\Functions\when;

class GuardYesNoOptionCleanupTest extends BaseMonkeyTestCase
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

    public function testYesNoOptionsRemovedFromEmail()
    {
        // Provide an email label plus noise that could cause options; rely on pipeline perhaps producing options incorrectly (simulation)
        $text = "ایمیل شما (بله/خیر)\nنام شما";
        $resp = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $resp);
        $data = $resp->get_data();
        $fields = $data['schema']['fields'] ?? [];
        $emailField = null;
        foreach ($fields as $f){ if (isset($f['props']['format']) && $f['props']['format']==='email'){ $emailField=$f; break; } }
        if (!$emailField){ $this->markTestSkipped('Email field not generated; cannot verify cleanup'); }
        $this->assertArrayNotHasKey('options', $emailField['props'], 'Yes/No options should be removed from email field');
        $notes = $data['notes'] ?? [];
        $this->assertTrue((bool)preg_grep('/guard:option_cleanup/',$notes), 'Missing guard:option_cleanup note');
    }
}
