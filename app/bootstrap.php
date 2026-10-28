<?php
define('ROOT',dirname(__DIR__));
define('APP',ROOT.'/app');
define('STORAGE',ROOT.'/storage');
define('APP_VERSION',trim((string)@file_get_contents(ROOT.'/VERSION'))?:'1.0.0');
if(!is_file(ROOT.'/config.php')){
  if(PHP_SAPI==='cli'){fwrite(STDERR,"Not installed. Open install.php in your browser.\n");exit(1);}
  header('Location: install.php');exit;}
$GLOBALS['CFG']=require ROOT.'/config.php';
error_reporting(E_ALL);
ini_set('display_errors',!empty($GLOBALS['CFG']['debug'])?'1':'0');
ini_set('log_errors','1');
ini_set('error_log',STORAGE.'/logs/php-error.log');
mb_internal_encoding('UTF-8');
require APP.'/vendor/autoload.php';
spl_autoload_register(function($c){$f=APP.'/lib/'.$c.'.php';if(is_file($f))require $f;});
require APP.'/helpers.php';
date_default_timezone_set(setting('timezone','Asia/Kolkata')?:'Asia/Kolkata');
DB::q("SET time_zone=?",[date('P')]);
if(PHP_SAPI!=='cli'&&session_status()===PHP_SESSION_NONE){
  session_name('setu_sid');
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
  session_start();}
