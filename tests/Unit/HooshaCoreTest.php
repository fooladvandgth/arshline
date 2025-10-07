<?php
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class HooshaCoreTest extends TestCase
{
    protected function getApiReflection(): ReflectionClass
    {
        return new ReflectionClass('Arshline\\Core\\Api');
    }

    protected function invokeProtected($method, array $args = [])
    {
        $ref = $this->getApiReflection();
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    public function testSchemaValidationValid()
    {
        $schema = ['fields' => [ ['type'=>'short_text','label'=>'ایمیل'], ['type'=>'long_text','label'=>'توضیحات'] ]];
        $res = $this->invokeProtected('hoosha_validate_schema', [$schema]);
        $this->assertTrue($res['ok'], 'Expected ok schema');
        $this->assertEmpty($res['errors']);
    }

    public function testSchemaValidationInvalid()
    {
        $schema = ['fields' => [ ['label'=>'بدون نوع'], 'not_object', ['type'=>'','label'=>''] ] ];
        $res = $this->invokeProtected('hoosha_validate_schema', [$schema]);
        $this->assertFalse($res['ok']);
        $this->assertNotEmpty($res['errors']);
        $this->assertTrue(in_array('missing_type(index=0)', $res['errors']) || in_array('missing_type(index=2)', $res['errors']));
    }

    public function testNotePrefixNormalization()
    {
        $legacy = [
            'final_issue(format_missing)','final_note(ok)','restored_file_upload(1)','deduplicated_fields(2)','required_enforced(mobile_ir)','model_call_failed','fallback_from_model_failure','options_normalized(albaloo)','some_custom'
        ];
        $out = $this->invokeProtected('hoosha_normalize_note_prefixes', [$legacy]);
        // All should have one of allowed prefixes
        foreach ($out as $n){
            $this->assertMatchesRegularExpression('/^(pipe:|heur:|ai:|perf:)/', $n, 'Prefixed: '.$n);
        }
        $this->assertTrue(in_array('ai:final_issue(format_missing)', $out));
        $this->assertTrue(in_array('ai:note(ok)', $out));
    }

    public function testSeverityClassification()
    {
        $err = $this->invokeProtected('hoosha_classify_issue_severity', ['format_invalid']);
        $warn = $this->invokeProtected('hoosha_classify_issue_severity', ['length_optimize']);
        $info = $this->invokeProtected('hoosha_classify_issue_severity', ['misc_note']);
        $this->assertEquals('error', $err);
        $this->assertEquals('warning', $warn);
        $this->assertEquals('info', $info);
    }
}
