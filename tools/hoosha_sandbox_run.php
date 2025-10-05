<?php
require_once __DIR__ . '/../src/Core/HooshaSandbox.php';
if (!class_exists('Arshline\\Core\\HooshaSandbox')){ fwrite(STDERR,"Sandbox class missing\n"); exit(2);} 

use Arshline\Core\HooshaSandbox;

$args = $_SERVER['argv']; array_shift($args);
$opts = ['file'=>null,'text'=>null,'no-guard'=>false];
foreach ($args as $a){
  if (preg_match('/^--file=(.+)$/',$a,$m)) $opts['file']=$m[1];
  elseif (preg_match('/^--text=(.+)$/',$a,$m)) $opts['text']=$m[1];
  elseif ($a==='--no-guard') $opts['no-guard']=true;
}
if (!$opts['text'] && $opts['file']){
  if (!is_file($opts['file'])){ fwrite(STDERR,"File not found\n"); exit(3);} 
  $opts['text']=file_get_contents($opts['file']);
}
if (!$opts['text']){
  fwrite(STDERR,"Provide --text=... or --file=path\n"); exit(1);
}
$res = HooshaSandbox::process($opts['text'], !$opts['no-guard']);
echo json_encode($res, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),"\n";