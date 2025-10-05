<?php
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../tests/bootstrap.php';
require __DIR__.'/../src/Core/Api.php';
use Arshline\Core\Api;
use function Brain\Monkey\Functions\when;

// Brain Monkey lifecycle
\Brain\Monkey\setUp();
when('current_user_can')->justReturn(true);
when('get_current_user_id')->justReturn(1);
when('get_option')->alias(fn($k)=>null);

class R extends WP_REST_Request { public function __construct($text){ $this->set_body(json_encode(['user_text'=>$text], JSON_UNESCAPED_UNICODE)); } }
$req = new R("اسمت چیه\nامروز چه تاریخیه\nکد ملیتو بده\nشماره تلفن رو بده . اجباری");
$res = Api::hoosha_prepare($req);
echo "Returned class: ", (is_object($res)? get_class($res): gettype($res)), "\n";
if ($res instanceof WP_Error){ echo 'WP_Error code='.$res->get_error_code().' msg='.$res->get_error_message()."\n"; }
if ($res instanceof WP_REST_Response){ var_dump($res->get_data()); }
\Brain\Monkey\tearDown();