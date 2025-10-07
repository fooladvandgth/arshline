<?php
$label='میای شرکت یا نه';
for($i=0;$i<mb_strlen($label,'UTF-8');$i++){
  $ch=mb_substr($label,$i,1,'UTF-8');
  echo $ch,' U+',strtoupper(dechex(mb_ord($ch,'UTF-8'))),"\n";
}
