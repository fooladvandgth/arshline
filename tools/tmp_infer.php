<?php
require __DIR__.'/../src/Core/Api.php';
use Arshline\Core\Api;
$txt="اسمت چیه\nامروز چه تاریخیه\nکد ملیتو بده\nشماره تلفن رو بده . اجباری";
$baseline = (new ReflectionClass(Api::class))->getMethod('hoosha_local_infer_from_text_v2');
$baseline->setAccessible(true);
$res = $baseline->invoke(null,$txt);
echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
