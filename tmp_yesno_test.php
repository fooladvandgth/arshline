<?php
$s='میای شرکت یا نه';
$match = preg_match('/یا\s+نه(\s|$)/u', mb_strtolower($s,'UTF-8'));
var_dump($match);
