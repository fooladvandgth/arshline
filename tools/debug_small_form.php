<?php
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../src/Core/Api.php';
$cls = new ReflectionClass(Arshline\Core\Api::class);
$m = $cls->getMethod('hoosha_local_infer_from_text_v2');
$m->setAccessible(true);
$text = "نام شما\nکد ملی شما\nشماره موبایل\nتوضیح کامل مشکل";
$res = $m->invoke(null, $text);
foreach (($res['fields']??[]) as $f){ echo ($f['label']??'?'),"|"; }
echo "\n";