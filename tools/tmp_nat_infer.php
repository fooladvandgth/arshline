<?php
if(function_exists('opcache_reset')) opcache_reset();
require __DIR__.'/../src/Core/Api.php';
use Arshline\Core\Api;
$txt="کد ملی\nکد ملی را دوباره وارد کن";
$ref=new ReflectionClass(Api::class); $m=$ref->getMethod('hoosha_local_infer_from_text_v2'); $m->setAccessible(true);
print_r($m->invoke(null,$txt));
