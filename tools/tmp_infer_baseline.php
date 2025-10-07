<?php
if(function_exists('opcache_reset')) opcache_reset();
require __DIR__.'/../src/Core/Api.php';
use Arshline\Core\Api;
$txt="نام شما\nکد ملی شما\nشماره موبایل\nتوضیح کامل مشکل";
$ref=new ReflectionClass(Api::class); $m=$ref->getMethod('hoosha_local_infer_from_text_v2'); $m->setAccessible(true);
$res=$m->invoke(null,$txt);
print_r($res);
