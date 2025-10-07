<?php
require __DIR__.'/vendor/autoload.php';
use Arshline\Hoosha\Pipeline\HooshaService;
$svc = new HooshaService(null);
$res = $svc->process("میای شرکت یا نه\nتوضیح مفصل بده", []);
echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
