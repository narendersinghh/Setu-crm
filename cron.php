<?php
// Background scheduler. Runs automatically (server crontab or built-in web scheduler).
// Manual options:  php /path/to/cron.php   or   https://your-site/cron.php?key=CRON_KEY
require __DIR__.'/app/bootstrap.php';
$cli=PHP_SAPI==='cli';
$chain=!$cli&&isset($_GET['chain']);
if(!$cli){
  session_write_close();
  if(!hash_equals(setting('cron_key'),(string)($_GET['key']??''))){http_response_code(403);exit('Forbidden');}
  if($chain)Cron::detach();}
if($cli)Settings::set('cron_cli_last',time());
$fp=fopen(STORAGE.'/tmp/cron.lock','c');
if(!flock($fp,LOCK_EX|LOCK_NB))exit($cli?"Already running\n":'');
$t0=time();
Settings::set('cron_last',$t0);
$span=$cli?50:($chain?50:25);
$n=0;
// Keep working (and waiting for scheduled jobs) for about a minute
while(time()-$t0<$span){
  $done=Queue::work(max(5,$span-(time()-$t0)));
  $n+=$done;
  if(!$done){if(!$chain&&!$cli)break;sleep(5);}
  Settings::set('cron_last',time());}
try{Automation::scan();Booking::remind();}catch(Throwable $e){logmsg('Scheduler: '.$e->getMessage());}
$rep=0;
if(setting('imap_host')&&time()-(int)setting('imap_last','0')>600){
  Settings::set('imap_last',time());
  try{$rep=Outreach::checkReplies();}catch(Throwable $e){logmsg('IMAP: '.$e->getMessage());}}
try{Updater::check();}catch(Throwable $e){}
foreach(glob(STORAGE.'/tmp/upd-*',GLOB_ONLYDIR) as $d)if(filemtime($d)<time()-86400)Updater::rrm($d);
if(mt_rand(1,30)===1)DB::q("DELETE FROM jobs WHERE status='done' AND created_at<NOW()-INTERVAL 14 DAY");
flock($fp,LOCK_UN);fclose($fp);
// Web scheduler: start the next round unless a real server cron is active
if($chain&&time()-(int)DB::val("SELECT v FROM settings WHERE k='cron_cli_last'")>180&&DB::val("SELECT v FROM settings WHERE k='scheduler'")!=='off'){Settings::set('cron_kick',time());Cron::async(rtrim(setting('cron_url',base_url('')),'/').'/cron.php?key='.urlencode(setting('cron_key')).'&chain=1');}
if($cli)echo "Processed $n job(s), $rep new repl(ies)\n";
