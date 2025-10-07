<?php
require_once __DIR__ . '/../arshline.php';
if (!class_exists('Arshline\\Core\\Api')){
    require_once __DIR__ . '/../src/Core/Api.php';
}
use Arshline\Core\Api;
$path = $argv[1] ?? null;
if(!$path || !is_file($path)){ fwrite(STDERR, "Usage: php tools/simulate_from_file.php <file>\n"); exit(1);} 
$text = file_get_contents($path);
class _SimReq { private array $b; public function __construct($t){ $this->b=['user_text'=>$t]; } public function get_body(){ return json_encode($this->b, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);} public function get_param($k){ return $this->b[$k]??null;} public function get_json_params(){return $this->b;} }
if (!function_exists('update_option')){ function update_option($k,$v){} }
if (!function_exists('get_option')){ function get_option($k,$d=null){ return $d; } }
update_option('arshline_settings',['ai_guard_enabled'=>true]);
$req = new _SimReq($text);
$resp = Api::hoosha_prepare($req);
if ($resp instanceof \WP_Error){ echo json_encode(['ok'=>false,'error'=>$resp->get_error_message()], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
$data = $resp->get_data();
echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
