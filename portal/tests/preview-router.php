<?php
// Development only. Bind PHP's built-in server to loopback. Never deploy this file.
declare(strict_types=1);
if (PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)) { http_response_code(403); exit; }
$_SERVER['HTTPS']='on';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with($path,'/assets/') && is_file(dirname(__DIR__).'/public'.$path)) return false;
require dirname(__DIR__).'/public/index.php';
