<?php
if(function_exists('opcache_reset')) opcache_reset();
require __DIR__.'/../src/Core/Api.php';
use Arshline\Core\Api;
// Simulate request
class DummyReq { function get_body(){ return json_encode(['user_text'=>"کد ملی\nکد ملی را دوباره وارد کن"], JSON_UNESCAPED_UNICODE); } }
$r=new DummyReq();
$res=Api::hoosha_prepare($r);
if($res instanceof \WP_REST_Response){ print_r($res->get_data()); } else { var_dump($res); }
