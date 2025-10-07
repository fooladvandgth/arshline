<?php
require __DIR__.'/vendor/autoload.php';
use Arshline\Hoosha\Pipeline\Normalizer;
$f1='شماره موبایل شما';
$f2='شماره تلفن همراه';
var_dump(Normalizer::tokenize($f1));
var_dump(Normalizer::tokenize($f2));
