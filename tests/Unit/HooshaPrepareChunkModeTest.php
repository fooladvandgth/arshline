<?php
namespace Arshline\Tests\Unit;

use Arshline\Tests\Unit\BaseMonkeyTestCase;
use function Brain\Monkey\Functions\when;
use Arshline\Core\Api;
use WP_REST_Request;

/**
 * Validates chunk-mode path (large input triggers splitting) and merged schema aggregation.
 */
class HooshaPrepareChunkModeTest extends BaseMonkeyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    when('current_user_can')->justReturn(true);
    when('get_current_user_id')->justReturn(1);
        // Provide AI config so model path executes (avoid ai_not_configured WP_Error)
    when('get_option')->alias(function($key){
            if ($key === 'arshline_settings'){
                return [
                    'ai_enabled' => true,
                    'ai_base_url' => 'https://fake.local',
                    'ai_api_key' => 'test-key',
                ];
            }
            return null;
        });
        // Stub WP HTTP layer to simulate model JSON output per chunk
        $counter = 0;
    when('wp_remote_post')->alias(function($endpoint,$args) use (&$counter){
            $counter++;
            // Build simple single-field JSON for each chunk
            $fieldLabel = 'فیلد چانک '.$counter;
            $modelPayload = [
                'choices' => [[ 'message' => [ 'content' => json_encode([
                    'edited_text' => $fieldLabel, 
                    'schema' => [ 'fields' => [[ 'type'=>'short_text','label'=>$fieldLabel,'required'=>false,'props'=>[] ]] ],
                    'notes' => [],
                    'confidence' => 0.92
                ], JSON_UNESCAPED_UNICODE) ]]]
            ];
            return [ 'response' => ['code'=>200], 'body' => json_encode($modelPayload, JSON_UNESCAPED_UNICODE) ];
        });
    when('wp_remote_retrieve_response_code')->alias(fn($r)=> $r['response']['code'] ?? 0);
    when('wp_remote_retrieve_body')->alias(fn($r)=> $r['body'] ?? '');
    when('is_wp_error')->alias(fn($v)=> false);
    }

    // tearDown inherited

    private function callPrepare(string $text)
    {
        $req = new WP_REST_Request('POST','/arshline/v1/hoosha/prepare');
        $req->set_body(json_encode(['user_text'=>$text], JSON_UNESCAPED_UNICODE));
        return Api::hoosha_prepare($req);
    }

    public function testLargeInputTriggersChunkMode()
    {
        // Build > 60 lines to trigger chunk mode via lineCount condition
        $lines = [];
        for ($i=1; $i<=75; $i++){
            $lines[] = 'سوال شماره '.$i;
        }
        $text = implode("\n", $lines);
        $res = $this->callPrepare($text);
        $this->assertInstanceOf(\WP_REST_Response::class, $res, 'Expected WP_REST_Response for chunk-mode path');
        $data = $res->get_data();
        $this->assertTrue($data['ok'] ?? false, 'ok flag missing');
        $notes = $data['notes'] ?? [];
        $this->assertNotEmpty($notes, 'Expected notes including chunk markers');
        $hasChunked = false; $hasProgress = false; $hasMerged=false;
        foreach ($notes as $n){
            if (strpos($n,'pipe:chunked_input(')!==false) $hasChunked=true;
            if (strpos($n,'pipe:chunk_progress(')!==false) $hasProgress=true;
            if (strpos($n,'pipe:chunks_merged(')!==false) $hasMerged=true;
        }
        $this->assertTrue($hasChunked, 'Missing chunked_input note');
        $this->assertTrue($hasProgress, 'Missing chunk_progress notes');
        $this->assertTrue($hasMerged, 'Missing chunks_merged note');
        $schema = $data['schema'] ?? [];
        $fields = $schema['fields'] ?? [];
        $this->assertGreaterThan(1, count($fields), 'Merged schema should include >1 field from chunks');
        // Ensure distinct labels (no accidental duplicate collapse in this path)
        $labels = array_map(fn($f)=>$f['label']??'', $fields);
        $this->assertGreaterThan(1, count(array_unique($labels)), 'Expected unique labels from per-chunk outputs');
    }
}
