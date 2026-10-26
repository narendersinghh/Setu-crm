<?php
define('ROOT',__DIR__);define('APP',ROOT.'/app');define('STORAGE',ROOT.'/storage');
define('APP_VERSION',trim((string)@file_get_contents(ROOT.'/VERSION'))?:'1.0.0');
if(is_file(ROOT.'/config.php')){header('Location: index.php');exit;}
require APP.'/helpers.php';
foreach(['DB','Settings','Seed','Migrator','Cron'] as $c)require APP.'/lib/'.$c.'.php';
$req=['PHP 8.0+'=>version_compare(PHP_VERSION,'8.0','>='),'pdo_mysql'=>extension_loaded('pdo_mysql'),'curl'=>extension_loaded('curl'),'mbstring'=>extension_loaded('mbstring'),'dom'=>extension_loaded('dom'),'gd'=>extension_loaded('gd'),'openssl'=>extension_loaded('openssl'),'zip (for updates)'=>class_exists('ZipArchive'),'storage/ writable'=>is_writable(STORAGE),'root folder writable (config.php)'=>is_writable(ROOT)];
$err='';$P=$_POST;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
  try{
    foreach(['host','name','user','aname','aemail','apass'] as $k)if(trim($P[$k]??'')==='')throw new Exception('Please fill all required fields.');
    if(strlen($P['apass'])<8)throw new Exception('Admin password must be at least 8 characters.');
    $cfg=['db'=>['host'=>trim($P['host']),'port'=>(int)($P['port']?:3306),'name'=>trim($P['name']),'user'=>trim($P['user']),'pass'=>$P['pass']??''],'app_key'=>bin2hex(random_bytes(32)),'url'=>rtrim(trim($P['url']??''),'/'),'debug'=>false];
    $GLOBALS['CFG']=$cfg;
    DB::pdo();
    date_default_timezone_set('Asia/Kolkata');
    DB::q("SET time_zone=?",[date('P')]);
    Migrator::run();
    if(!DB::val('SELECT COUNT(*) FROM users WHERE email=?',[trim($P['aemail'])]))DB::insert('users',['name'=>trim($P['aname']),'email'=>trim($P['aemail']),'password'=>password_hash($P['apass'],PASSWORD_DEFAULT),'role'=>'super']);
    foreach(['reports','uploads','fonts','cache','logs','tmp','backups'] as $d)@mkdir(STORAGE.'/'.$d,0755,true);
    @mkdir(ROOT.'/assets/uploads',0755,true);
    if(file_put_contents(ROOT.'/config.php',"<?php\n// Setu configuration – keep this file private.\nreturn ".var_export($cfg,true).";\n")===false)throw new Exception('Could not write config.php – make the folder writable.');
    @chmod(ROOT.'/config.php',0640);
    Settings::set('cron_tried',1);if(Cron::install())Settings::set('cron_cli_last',time());
    header('Location: index.php?r=login&installed=1');exit;}
  catch(Throwable $e){$err=$e->getMessage();}}
$guess=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/')),'/');
$v=fn($k,$d='')=>e($P[$k]??$d);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install · Setu</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Inter+Tight:wght@600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/app.css"></head><body class="bare"><div class="auth"><div class="auth-l"><img src="assets/img/setu-logo.png" alt="Setu" style="width:44px;height:44px"><h2>Install Setu</h2><p>Takes about one minute. You need a MySQL database (cPanel → MySQL® Database Wizard).</p><ul class="req"><?php foreach($req as $k=>$ok):?><li class="<?=$ok?'ok':'no'?>"><?=$ok?'✓':'✕'?> <?=e($k)?></li><?php endforeach?></ul></div><form class="auth-r" method="post"><h1>Setup</h1><?php if($err):?><div class="al al-err"><span><?=e($err)?></span></div><?php endif?><h4>Database</h4><div class="row"><label>Host<input name="host" value="<?=$v('host','localhost')?>" required></label><label style="max-width:110px">Port<input name="port" value="<?=$v('port','3306')?>"></label></div><label>Database name<input name="name" value="<?=$v('name')?>" required></label><div class="row"><label>Username<input name="user" value="<?=$v('user')?>" required></label><label>Password<input type="password" name="pass" value="<?=$v('pass')?>"></label></div><h4>Admin account</h4><label>Your name<input name="aname" value="<?=$v('aname')?>" required></label><label>Email (login)<input type="email" name="aemail" value="<?=$v('aemail')?>" required></label><label>Password (8+ characters)<input type="password" name="apass" minlength="8" required></label><label>Site URL<input name="url" value="<?=$v('url',$guess)?>"><small>Used for public PDF links in WhatsApp/email.</small></label><button class="btn btn-y btn-b">Install</button></form></div></body></html>
