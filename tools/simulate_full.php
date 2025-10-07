<?php
/**
 * Full Hoosha simulator with real WordPress bootstrap.
 * Usage examples:
 *   php tools/simulate_full.php --file=tmp_input.txt --no-model --guard=1
 *   php tools/simulate_full.php --text="سوال تست" --wp-root=C:/laragon/www/ARSHLINE
 * Options:
 *   --file=PATH        Read multi-line Persian questions from file
 *   --text=STRING      Direct text (overrides --file)
 *   --wp-root=PATH     Explicit WordPress root (where wp-load.php lives). Auto-detect if omitted.
 *   --no-model         Disable model refinement (ai_enabled=false)
 *   --guard=0|1        Force guard enable/disable (otherwise keep existing option)
 *   --raw              Only print final JSON (no summary)
 */

$argvCopy = $argv; array_shift($argvCopy);
$opts = [ 'file'=>null,'text'=>null,'wp-root'=>null,'no-model'=>false,'guard'=>null,'raw'=>false ];
foreach ($argvCopy as $a){
    if (preg_match('/^--file=(.+)$/',$a,$m)) $opts['file']=$m[1];
    elseif (preg_match('/^--text=(.+)$/u',$a,$m)) $opts['text']=$m[1];
    elseif (preg_match('/^--wp-root=(.+)$/',$a,$m)) $opts['wp-root']=$m[1];
    elseif ($a==='--no-model') $opts['no-model']=true;
    elseif (preg_match('/^--guard=(0|1)$/',$a,$m)) $opts['guard'] = $m[1]==='1';
    elseif ($a==='--raw') $opts['raw']=true;
}
if (!$opts['text'] && $opts['file']){
    if (!is_file($opts['file'])){ fwrite(STDERR,"[simulate_full] File not found: {$opts['file']}\n"); exit(2);} 
    $opts['text'] = file_get_contents($opts['file']);
}
if (!$opts['text']){ fwrite(STDERR,"[simulate_full] Provide --text=... or --file=...\n"); exit(1);} 

// Detect WP root (wp-load.php) walking up if not provided.
if ($opts['wp-root']){
    $wpRoot = rtrim($opts['wp-root'],'/\\');
} else {
    // From tools dir: .../wp-content/plugins/arshline/tools  -> root is up 4 levels
    $wpRoot = dirname(__DIR__,4);
}
$wpLoad = $wpRoot . DIRECTORY_SEPARATOR . 'wp-load.php';
if (!is_file($wpLoad)){
    fwrite(STDERR,"[simulate_full] wp-load.php not found at: $wpLoad\nUse --wp-root=PATH if auto-detect is wrong.\n");
    exit(3);
}
// Populate minimal $_SERVER keys for wp-config or plugins expecting web context
if (empty($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'localhost';
if (empty($_SERVER['REQUEST_SCHEME'])) $_SERVER['REQUEST_SCHEME'] = 'http';
if (empty($_SERVER['SERVER_NAME'])) $_SERVER['SERVER_NAME'] = 'localhost';
if (empty($_SERVER['REQUEST_METHOD'])) $_SERVER['REQUEST_METHOD'] = 'POST';
if (empty($_SERVER['REMOTE_ADDR'])) $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once $wpLoad; // Boots WordPress fully

// Ensure an admin user is logged in so permission checks pass
if (function_exists('wp_set_current_user')){
    $admins = get_users(['role'=>'administrator','number'=>1]);
    if (!$admins){
        // create a temporary admin if none exists
        $uid = wp_create_user('cli_admin_temp','cli_admin_temp_pass','cli_admin_temp@example.com');
        if (!is_wp_error($uid)){
            $u = new WP_User($uid); $u->set_role('administrator');
            wp_set_current_user($uid);
        }
    } else {
        wp_set_current_user($admins[0]->ID);
    }
}

// Ensure plugin main file is loaded (in case plugin not active in this CLI context)
$pluginMain = __DIR__ . '/../arshline.php';
if (is_file($pluginMain)) require_once $pluginMain;

// Configure settings
$settings = get_option('arshline_settings', []);
if ($opts['no-model']){
    $settings['ai_enabled'] = false;
    unset($settings['ai_api_key']);
    unset($settings['ai_base_url']);
} else {
    // Provide fake values if missing to let pipeline attempt model (may fail gracefully)
    if (empty($settings['ai_api_key'])) $settings['ai_api_key']='TEST_KEY';
    if (empty($settings['ai_base_url'])) $settings['ai_base_url']='http://invalid.local';
    $settings['ai_enabled'] = true;
}
if ($opts['guard'] !== null){ $settings['ai_guard_enabled'] = $opts['guard']; }
elseif (!isset($settings['ai_guard_enabled'])){ $settings['ai_guard_enabled']=true; }
update_option('arshline_settings', $settings);

// Build WP_REST_Request
if (!class_exists('WP_REST_Request')){ fwrite(STDERR,"[simulate_full] WP_REST_Request unavailable after bootstrap.\n"); exit(4);} 
$req = new \WP_REST_Request('POST','/arshline/v1/hoosha_prepare');
$req->set_body_params(['user_text'=>$opts['text']]);

// Call real API
if (!class_exists('Arshline\\Core\\Api')){
    require_once __DIR__ . '/../src/Core/Api.php';
}
$resp = \Arshline\Core\Api::hoosha_prepare($req);
if ($resp instanceof \WP_Error){
    $out = [ 'ok'=>false, 'error'=>$resp->get_error_message() ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),"\n"; exit(0);
}
$data = $resp->get_data();

if ($opts['raw']){
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),"\n"; exit(0);
}

$fields = $data['schema']['fields'] ?? [];
$notes = $data['notes'] ?? [];
$guard = $data['guard'] ?? [];

// Simple duplicate estimate (canonical label)
$canonSeen = [];$dup=0;foreach ($fields as $f){ $lbl=$f['label']??''; $c=preg_replace('/[\s[:punct:]]+/u','', mb_strtolower($lbl,'UTF-8')); if(isset($canonSeen[$c])) $dup++; else $canonSeen[$c]=1; }
$summary = [
  'field_count'=>count($fields),
  'duplicates_estimated'=>$dup,
  'guard_approved'=>$guard['approved']??null,
  'guard_issues'=>$guard['issues']??[],
];

echo "=== Full Hoosha Simulation (WordPress) ===\n";
foreach ($summary as $k=>$v){ if (is_array($v)) $v=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); echo str_pad($k,22).": $v\n"; }

echo "\n-- Fields --\n";
foreach ($fields as $i=>$f){
    $lbl=$f['label']??''; $t=$f['type']??''; $opts=$f['options']??[]; $optStr=$opts?(' ['.implode(',',$opts).']'):''; echo sprintf('%02d. %s (%s)%s',$i+1,$lbl,$t,$optStr)."\n"; }

echo "\n-- Guard Issues Detail --\n";
if (!empty($guard['issues_detail'])){
    echo json_encode($guard['issues_detail'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),"\n";
} else { echo "(none)\n"; }

echo "\n-- Notes (truncated) --\n"; $maxN=40; $c=0; foreach ($notes as $n){ echo $n."\n"; if(++$c>=$maxN){ if(count($notes)>$maxN) echo "... (".(count($notes)-$maxN)." more)\n"; break; } }

echo "\n-- Raw JSON (final) --\n"; echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),"\n";
