<?php
use PHPUnit\Framework\TestCase;
use Arshline\Guard\GuardService;

class GuardServiceUserTextLinkTest extends TestCase
{
    public function test_user_text_line_not_flagged_hallucination_when_in_user_text()
    {
        // Baseline missing one question that appears in user_text
        $baseline = [ 'fields' => [
            ['type'=>'short_text','label'=>'اسمت رو بنویس'],
            ['type'=>'short_text','label'=>'شماره تلفن ایران']
        ] ];
        // Refined schema includes an extra field derived from user_text ("شماره تلفن خارجت")
        $schema = [ 'fields' => [
            ['type'=>'short_text','label'=>'اسمت رو بنویس'],
            ['type'=>'short_text','label'=>'شماره تلفن ایران'],
            ['type'=>'short_text','label'=>'شماره تلفن خارجت']
        ] ];
        $userText = "اسمت رو بنویس\nشماره تلفن ایران\nشماره تلفن خارجت";

        // Disable additions (strict) to test relaxed user_text semantic linking logic
        if (!function_exists('get_option')){
            function get_option($name, $default = []) { return ['guard_semantic_similarity_min'=>0.8]; }
        }
        $guard = new GuardService(null, sys_get_temp_dir().'/guard_test.log');
        $res = $guard->evaluate($baseline, $schema, $userText, []);
        $issues = $res['issues'] ?? [];
        // Should not contain removed_ai_added(1) because user_text relaxed match should protect it
        $this->assertFalse((bool)array_filter($issues, fn($i)=>str_starts_with($i,'removed_ai_added')), 'Unexpected removal flagged. Issues: '.json_encode($issues,JSON_UNESCAPED_UNICODE));
    }
}
