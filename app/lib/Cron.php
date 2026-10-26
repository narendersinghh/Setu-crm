<?php
// Automatic background scheduler: real crontab when the host allows it, otherwise a self-restarting web scheduler.
final class Cron{
  static function cmd(){return self::php().' '.ROOT.'/cron.php';}
  static function line(){return '* * * * * '.self::cmd().' >/dev/null 2>&1';}
  static function php(){
    foreach(['/usr/bin/php','/usr/local/bin/php','/opt/alt/php83/usr/bin/php','/opt/alt/php82/usr/bin/php','/opt/alt/php81/usr/bin/php',PHP_BINDIR.'/php'] as $p)if(@is_executable($p))return $p;
    return 'php';}
  static function canShell(){
    if(!function_exists('shell_exec'))return false;
    $d=array_map('trim',explode(',',(string)ini_get('disable_functions')));
    return !in_array('shell_exec',$d,true);}
  // Try to add Setu to the server crontab (works on hosts that allow it, e.g. many Hostinger/cPanel plans)
  static function install(){
    if(!self::canShell())return false;
    $cur=(string)@shell_exec('crontab -l 2>/dev/null');
    if(strpos($cur,ROOT.'/cron.php')!==false)return self::installed();
    $tmp=STORAGE.'/tmp/crontab.txt';
    file_put_contents($tmp,rtrim($cur)."\n".self::line()."\n");
    @shell_exec('crontab '.escapeshellarg($tmp).' 2>&1');
    @unlink($tmp);
    return self::installed();}
  static function installed(){return self::canShell()&&strpos((string)@shell_exec('crontab -l 2>/dev/null'),ROOT.'/cron.php')!==false;}
  static function mode(){
    if(time()-(int)setting('cron_cli_last','0')<300)return 'server';
    if(time()-(int)setting('cron_last','0')<300)return 'web';
    return 'off';}
  // Called from normal page loads/webhooks: restarts the web scheduler if it has stopped
  static function due(){return setting('scheduler','auto')!=='off'&&time()-(int)setting('cron_last','0')>=150&&time()-(int)setting('cron_kick','0')>=60;}
  static function kick($force=false){
    try{
      if(!$force&&!self::due())return;
      Settings::set('cron_kick',time());
      if(isset($_SERVER['HTTP_HOST'])&&PHP_SAPI!=='cli'){
        $u=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/');
        if(setting('cron_url')!==$u)Settings::set('cron_url',$u);}
      self::async(rtrim(setting('cron_url',base_url('')),'/').'/cron.php?key='.urlencode(setting('cron_key')).'&chain=1');}
    catch(Throwable $e){logmsg('Cron kick: '.$e->getMessage());}}
  static function async($url){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>1500,CURLOPT_CONNECTTIMEOUT_MS=>1500,CURLOPT_NOSIGNAL=>1,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'Setu-Scheduler']);
    curl_exec($ch);curl_close($ch);}
  // Close the HTTP connection early and keep running in the background
  static function detach(){
    ignore_user_abort(true);
    @set_time_limit(180);
    if(function_exists('fastcgi_finish_request')){echo 'OK';fastcgi_finish_request();return;}
    if(function_exists('litespeed_finish_request')){echo 'OK';litespeed_finish_request();return;}
    while(ob_get_level())ob_end_clean();
    header('Connection: close');header('Content-Length: 2');echo 'OK';flush();}}
