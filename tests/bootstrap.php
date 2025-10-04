<?php
// Minimal bootstrap for tests – rely on Composer autoload & Brain Monkey provided functions API.
require_once __DIR__ . '/../vendor/autoload.php';

$bmApi = __DIR__ . '/../vendor/brain/monkey/inc/api.php';
if (file_exists($bmApi)) {
    require_once $bmApi;
}

// Intentionally DO NOT define common WP procedural functions (current_user_can, register_rest_route, etc.)
// so Brain Monkey / Patchwork can intercept them. Define only constants or classes that won't be patched.
if (!defined('ARRAY_A')) { define('ARRAY_A','ARRAY_A'); }

// REST-like classes / errors
if (!class_exists('WP_REST_Request')) { class WP_REST_Request implements \ArrayAccess { private array $p=[]; private string $b=''; public function __construct($m='GET',$r=''){} public function set_param($n,$v){$this->p[$n]=$v;} public function get_param($n){return $this->p[$n]??null;} public function __get($n){return $this->p[$n]??null;} public function __set($n,$v){$this->p[$n]=$v;} public function offsetExists($o): bool {return isset($this->p[$o]);} public function offsetGet($o): mixed {return $this->p[$o]??null;} public function offsetSet($o,$v): void {$this->p[$o]=$v;} public function offsetUnset($o): void {unset($this->p[$o]);} public function set_body($b){$this->b=(string)$b;} public function get_body(){return $this->b;} } }
if (!class_exists('WP_REST_Response')) { class WP_REST_Response { protected $d; protected $s; public function __construct($d,$s=200){$this->d=$d;$this->s=$s;} public function get_data(){return $this->d;} public function get_status(){return $this->s;} } }
if (!class_exists('WP_REST_Server')) { class WP_REST_Server { const READABLE='GET'; const CREATABLE='POST'; const EDITABLE='PUT'; const DELETABLE='DELETE'; const ALLMETHODS='GET,POST,PUT,DELETE'; } }
if (!class_exists('WP_Error')) { class WP_Error { protected $c; protected $m; protected $data; public function __construct($c='',$m='',$data=[]) { $this->c=$c;$this->m=$m;$this->data=$data; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->data;} } }
// Network helpers purposely omitted for patching; if real behavior needed, tests should stub them.

// $wpdb very small mock
global $wpdb; if (!$wpdb) { $wpdb = new class { public $prefix='wp_'; public $insert_id=1; public function get_results($q,$o=OBJECT){ return []; } public function prepare($q,...$a){ return $q; } public function insert($t,$d){ $this->insert_id=1; return true; } public function update($t,$d,$w){ return true; } }; }

