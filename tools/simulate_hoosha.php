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
 *   --guard-mode=MODE   Force GuardUnit mode (diagnostic|corrective)
 */

require_once __DIR__ . '/../arshline.php'; // ensure WP bootstrap in plugin context if present
if (!class_exists('Arshline\Core\Api')){
    require_once __DIR__ . '/../src/Core/Api.php';
}

use Arshline\Core\Api;

// Basic arg parse
$args = $_SERVER['argv'];
array_shift($args);
$opts = [ 'file'=>null,'id'=>null,'text'=>null,'no-model'=>false,'guard'=>null,'json-only'=>false,'guard-mode'=>null ];
foreach ($args as $a){
    if (preg_match('/^--file=(.+)$/',$a,$m)) $opts['file']=$m[1];
    elseif (preg_match('/^--id=(.+)$/',$a,$m)) $opts['id']=$m[1];
    elseif (preg_match('/^--text=(.+)$/',$a,$m)) $opts['text']=$m[1];
    elseif ($a==='--no-model') $opts['no-model']=true;
    elseif (preg_match('/^--guard=(0|1)$/',$a,$m)) $opts['guard']=$m[1]==='1';
    elseif ($a==='--json-only') $opts['json-only']=true;
    elseif (preg_match('/^--guard-mode=(diagnostic|corrective)$/',$a,$m)) $opts['guard-mode']=$m[1];
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

// ---- WP / REST stubs if WP not loaded ----
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        protected $data; protected $status; protected $headers = [];
        public function __construct($data = null, int $status = 200, array $headers = []){ $this->data=$data; $this->status=$status; $this->headers=$headers; }
        public function get_data(){ return $this->data; }
        public function get_status(){ return $this->status; }
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error extends \Exception {
        protected $codeKey; protected $extra;
        public function __construct($code='error', $message='', $data=[]){ parent::__construct($message); $this->codeKey=$code; $this->extra=$data; }
        public function get_error_message(){ return $this->getMessage(); }
    }
}
if (!function_exists('current_user_can')){ function current_user_can($cap){ return true; } }
if (!function_exists('get_current_user_id')){ function get_current_user_id(){ return 1; } }
if (!function_exists('update_option')){ function update_option($k,$v){ $GLOBALS['__sim_opts'][$k]=$v; } }
if (!function_exists('get_option')){ function get_option($k,$d=null){ return $GLOBALS['__sim_opts'][$k] ?? $d; } }
if (!function_exists('set_transient')){ function set_transient($k,$v,$exp){ $GLOBALS['__sim_trans'][$k]=['v'=>$v,'e'=>time()+$exp]; } }
if (!function_exists('get_transient')){ function get_transient($k){ if(!isset($GLOBALS['__sim_trans'][$k])) return false; if($GLOBALS['__sim_trans'][$k]['e']<time()) return false; return $GLOBALS['__sim_trans'][$k]['v']; } }
if (!function_exists('delete_transient')){ function delete_transient($k){ unset($GLOBALS['__sim_trans'][$k]); } }

class _SimReq {
    private array $body; public function __construct(array $b){ $this->body=$b; }
    public function get_body(){ return json_encode($this->body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public function get_param($k){ return $this->body[$k] ?? null; }
    public function get_json_params(){ return $this->body; }
}

// Normalize literal \n sequences so user can pass --text="سطر1\nسطر2" on Windows
$inputText = str_replace('\\n', "\n", $inputText);

// Optionally force settings overrides (model / guard)
if (!function_exists('get_option')){
    function get_option($k,$d=null){ return $d; }
}
if ($opts['no-model']){
    if (!function_exists('update_option')){ function update_option($k,$v){} }
    $settings = [
        'ai_guard_enabled'=> ($opts['guard']===null? true : (bool)$opts['guard'])
    ];
    if ($opts['guard-mode']) $settings['guard_mode']=$opts['guard-mode'];
    update_option('arshline_settings',$settings);
} else {
    $settings = [
        'ai_enabled'=>true,
        'ai_api_key'=>'TEST_KEY',
        'ai_base_url'=>'http://invalid.local',
        'ai_guard_enabled'=> ($opts['guard']===null? true : (bool)$opts['guard']),
        'allow_ai_additions'=>false
    ];
    if ($opts['guard-mode']) $settings['guard_mode']=$opts['guard-mode'];
    update_option('arshline_settings',$settings);
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
