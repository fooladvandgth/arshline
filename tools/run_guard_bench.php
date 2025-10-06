<?php
/**
 * Benchmark script for Hoosha refinement + guard (root-cause validation).
 * Examples are symptomatic only, not the targets of the fix.
 * For illustration only — do not hardcode fixes for these.
 * Solution must generalize to unseen input patterns beyond the shown examples.
 */
use Arshline\Core\Api;
use Arshline\Guard\GuardService;
use Arshline\Hoosha\Pipeline\OpenAIModelClient;

if (php_sapi_name() !== 'cli') { echo "CLI only"; exit(1); }
require_once __DIR__.'/../vendor/autoload.php';

// Lightweight WP stubs if not in WP context
if (!function_exists('get_option')){ function get_option($k,$d=null){ return []; } }
if (!function_exists('wp_upload_dir')){ function wp_upload_dir(){ return ['basedir'=>sys_get_temp_dir()]; } }
if (!function_exists('current_user_can')){ function current_user_can($r){ return true; } }
if (!function_exists('wp_json_encode')){ function wp_json_encode($d,$f=0){ return json_encode($d,$f); } }
if (!function_exists('is_wp_error')){ function is_wp_error($x){ return false; } }
if (!function_exists('wp_remote_post')){ function wp_remote_post($url,$args){ return ['body'=>'{"choices":[{"message":{"content":"{\"fields\":[]}"}}]}','response'=>['code'=>200]]; } }
if (!function_exists('wp_remote_retrieve_response_code')){ function wp_remote_retrieve_response_code($r){ return $r['response']['code']??200; } }
if (!function_exists('wp_remote_retrieve_body')){ function wp_remote_retrieve_body($r){ return $r['body']??''; } }

// Scenario definitions (generic patterns)
$scenarios = [
  [ 'name'=>'basic_identity', 'questions'=>[ 'اسمت چیه', 'چند سالته', 'تاریخ تولدت', 'آیا امروز میای؟', 'کد ملی', 'شماره موبایل' ] ],
  [ 'name'=>'redundant_age', 'questions'=>[ 'سن شما چند سال است', 'چند سالته', 'سن', 'نام و نام خانوادگی', 'کد ملی', 'تاریخ تولد' ] ],
  [ 'name'=>'binary_inference', 'questions'=>[ 'آیا سیگار میکشی؟', 'آیا ورزش میکنی؟', 'ورزش مورد علاقه', 'ایمیل کاری', 'شماره تلفن همراه', 'تاریخ تولد' ] ],
  [ 'name'=>'format_focus', 'questions'=>[ 'کد ملی پدر', 'کد ملی مادر', 'شماره شناسنامه', 'شماره تلفن ثابت', 'شماره همراه', 'تاریخ شروع قرارداد' ] ],
  [ 'name'=>'noise_minimal', 'questions'=>[ 'نام', 'سن', 'یک جمله توضیحی که سوال نیست', 'کد ملی', 'تاریخ تولد', 'شماره همراه' ] ],
];

// Fake baseline: map questions directly (pre-refinement naive)
function make_baseline(array $questions): array {
  $fields=[]; foreach ($questions as $q){ $fields[]=[ 'label'=>$q, 'type'=>'short_text', 'props'=>[] ]; }
  return ['fields'=>$fields];
}

$modelKey = getenv('OPENAI_API_KEY') ?: 'sk-test';
$client = new OpenAIModelClient($modelKey,'gpt-4o-mini');
$guard = new GuardService(null); // no model client for guard secondary validation here

$results = [];
foreach ($scenarios as $sc){
  $baseline = make_baseline($sc['questions']);
  $userText = implode("\n", $sc['questions']);
  // Call refinement (may be empty due to stubbed HTTP)
  $refined = $client->refine($baseline, $userText, []);
  if (!$refined){
    // fallback: just use baseline
    $refined = ['fields'=>$baseline['fields']];
  }
  $guardRes = $guard->evaluate($baseline, $refined, $userText, []);
  $outFields = $guardRes['schema']['fields'] ?? [];
  $metrics = [
    'scenario'=>$sc['name'],
    'input_count'=>count($sc['questions']),
    'output_count'=>count($outFields),
    'hallucination_rate'=>($guardRes['diagnostics']['ai_added'] ?? 0) / max(1,count($outFields)),
    'duplicates_collapsed'=>$guardRes['diagnostics']['duplicates_collapsed'] ?? 0,
    'type_fixed'=>$guardRes['diagnostics']['type_fixed'] ?? 0,
    'options_corrected'=>$guardRes['diagnostics']['options_corrected'] ?? 0,
    'issues'=> $guardRes['issues'] ?? []
  ];
  $results[]=$metrics;
  echo json_encode($metrics, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}

// Aggregate summary
$agg = [ 'total_scenarios'=>count($results) ];
$fcSum=0; $fcMismatch=0; $hallSum=0.0;
foreach ($results as $r){
  if ($r['input_count'] !== $r['output_count']) $fcMismatch++;
  $hallSum += $r['hallucination_rate'];
}
$agg['fidelity_ok'] = ($fcMismatch===0);
$agg['avg_hallucination_rate'] = $hallSum / max(1,count($results));
file_put_contents('php://stderr', "SUMMARY ".json_encode($agg, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
// Standard machine-readable summary line for CI parsers
echo 'BENCH_SUMMARY total_scenarios='.$agg['total_scenarios'].' fidelity_ok='.(int)$agg['fidelity_ok'].' avg_hallucination_rate='.round($agg['avg_hallucination_rate'],4)."\n";
