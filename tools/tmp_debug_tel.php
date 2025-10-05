<?php
$qLower=mb_strtolower('شماره تلفن رو بده . اجباری','UTF-8');
var_dump($qLower);
var_dump(mb_strpos($qLower,'شماره')!==false);
var_dump(mb_strpos($qLower,'تلفن')!==false);
var_dump(preg_match('/شماره\s*تلفن|تلفن\s*شماره/u',$qLower));
