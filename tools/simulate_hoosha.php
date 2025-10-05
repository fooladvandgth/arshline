<?php
/**
 * CLI Simulation for Hoosha pipeline (baseline + optional model + guard) without HTTP.
 * Usage:
 *   php tools/simulate_hoosha.php --file=tools/hoosha_scenarios.json --id=baseline_small
 *   php tools/simulate_hoosha.php --text="نام و کد ملی خود را بنویس" --no-model
 * Options:
 *   --file=PATH         JSON scenarios file (array of {id,text})
 *   --id=SCENARIO_ID    Scenario id inside the JSON file
 *   --text=RAW_TEXT     Direct user text (overrides --id)
 *   --no-model          Force skip model refinement
 *   --guard=0|1         Force guard enable/disable (default auto if settings absent)
 *   --json-only         Output only final JSON (no commentary)
 */

require_once __DIR__ . '/../arshline.php'; // ensure WP bootstrap in plugin context if available
if (!class_exists('Arshline\Core\Api')){
    require_once __DIR__ . '/../src/Core/Api.php';
}

use Arshline\Core\Api;

// Basic arg parse
$args = $_SERVER['argv'];
array_shift($args);
$opts = [ 'file'=>null,'id'=>null,'text'=>null,'no-model'=>false,'guard'=>null,'json-only'=>false ];
foreach ($args as $a){
    if (preg_match('/^--file=(.+)$/',$a,$m)) $opts['file']=$m[1];
    elseif (preg_match('/^--id=(.+)$/',$a,$m)) $opts['id']=$m[1];
    elseif (preg_match('/^--text=(.+)$/',$a,$m)) $opts['text']=$m[1];
    elseif ($a==='--no-model') $opts['no-model']=true;
    elseif (preg_match('/^--guard=(0|1)$/',$a,$m)) $opts['guard']=$m[1]==='1';
    elseif ($a==='--json-only') $opts['json-only']=true;
}

// Scenario resolution
$inputText = $opts['text'];
if (!$inputText && $opts['file'] && $opts['id']){
    if (!is_file($opts['file'])){ fwrite(STDERR,"Scenario file not found\n"); exit(2); }
    $json = json_decode(file_get_contents($opts['file']), true);
    if (!is_array($json)){ fwrite(STDERR,"Invalid JSON in scenarios file\n"); exit(3); }
    foreach ($json as $row){ if (is_array($row) && ($row['id']??'')===$opts['id']){ $inputText = (string)($row['text']??''); break; } }
    if (!$inputText){ fwrite(STDERR,"Scenario id not found\n"); exit(4); }
}
if (!$inputText){ fwrite(STDERR,"No input text provided. Use --text or --file + --id.\n"); exit(1); }

// Shim minimal WP_REST_Request-like object
class _SimReq {
    private array $body; public function __construct(array $b){ $this->body=$b; }
    public function get_body(){ return json_encode($this->body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public function get_param($k){ return $this->body[$k] ?? null; }
    public function get_json_params(){ return $this->body; }
}

// Optionally force settings overrides (model / guard)
if (!function_exists('get_option')){
    function get_option($k,$d=null){ return $d; }
}
if ($opts['no-model']){
    // simulate absent AI config
    if (!function_exists('update_option')){ function update_option($k,$v){} }
    update_option('arshline_settings',['ai_guard_enabled'=> ($opts['guard']===null? true : (bool)$opts['guard']) ]);
} else {
    update_option('arshline_settings',[
        'ai_enabled'=>true,
        'ai_api_key'=>'TEST_KEY',
        'ai_base_url'=>'http://invalid.local',
        'ai_guard_enabled'=> ($opts['guard']===null? true : (bool)$opts['guard']),
        'allow_ai_additions'=>false
    ]);
}

$req = new _SimReq(['user_text'=>$inputText]);

$resp = Api::hoosha_prepare($req);
if ($resp instanceof \WP_Error){
    $out = [ 'ok'=>false, 'error'=>$resp->get_error_message() ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n"; exit(0);
}
$data = $resp->get_data();
if ($opts['json-only']){
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n"; exit(0);
}

// Pretty summary
$fields = $data['schema']['fields'] ?? [];
$dupCount = 0; $labels=[];
foreach ($fields as $f){ $lbl=$f['label']??''; $canon=preg_replace('/[\s[:punct:]]+/u','', mb_strtolower($lbl,'UTF-8')); if(isset($labels[$canon])) $dupCount++; else $labels[$canon]=1; }
$guardApproved = isset($data['guard']['approved']) ? ($data['guard']['approved']?'yes':'no') : 'n/a';
$notes = $data['notes'] ?? [];

$summary = [
  'ok'=>$data['ok'] ?? false,
  'field_count'=>count($fields),
  'duplicates_estimated'=>$dupCount,
  'guard_approved'=>$guardApproved,
  'guard_issues'=>$data['guard']['issues'] ?? [],
  'guard_errors'=>array_values(array_filter($notes, fn($n)=>str_contains($n,'guard:issues_error_count'))),
  'jalali_present'=> (bool)preg_grep('/جلالی/u', array_map(fn($f)=>$f['label']??'', $fields)),
  'yn_contamination'=> (bool)preg_grep('/\{بله \| خیر\}/u', $data['edited_text'] ?? '')
];

echo "=== Hoosha Simulation ===\n";
foreach ($summary as $k=>$v){ if (is_array($v)) $v=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); echo str_pad($k,22).": $v\n"; }

echo "\n--- Edited Text ---\n".($data['edited_text'] ?? '')."\n";
echo "\n--- Notes ---\n".implode("\n", $notes)."\n";
if (!empty($data['guard']['issues_detail'])){
    echo "\n--- Guard Issues Detail ---\n".json_encode($data['guard']['issues_detail'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
}

exit(0);
