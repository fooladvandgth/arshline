<?php
$lbl='میای شرکت یا نه';
$lblLower=mb_strtolower($lbl,'UTF-8');
$r1=preg_match('/یا\s+نه(\s|$)/u',$lblLower);
$r2=preg_match('/^می(?:ای|خوای|ری)\b/u',$lblLower);
var_dump([$lblLower,$r1,$r2]);
