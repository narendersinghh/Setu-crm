<?php
final class Updater{
  const KEEP=['config.php','storage','.htaccess.local','.git','.user.ini','php.ini','error_log'];
  private static function h(){$h=['Accept: application/vnd.github+json','User-Agent: Setu-Updater'];if($t=setting('github_token'))$h[]='Authorization: Bearer '.$t;return $h;}
  private static function repo(){return trim(setting('update_repo','nilesh-dn/anvesha'),'/ ');}
  private static function branch(){return setting('update_branch','main');}
  static function check($force=false){
    $c=jd(setting('update_cache'));
    if(!$force&&$c&&time()-($c['at']??0)<6*3600)return $c;
    $repo=self::repo();$br=self::branch();
    $v=http_json('https://api.github.com/repos/'.$repo.'/contents/version.json?ref='.rawurlencode($br),['headers'=>self::h(),'timeout'=>20]);
    if(isset($v['data']['content']))$v['data']=json_decode(base64_decode($v['data']['content']),true);
    $cm=http_json('https://api.github.com/repos/'.$repo.'/commits?per_page=15&sha='.rawurlencode($br),['headers'=>self::h(),'timeout'=>20]);
    $out=['at'=>time(),'ok'=>$v['code']===200,'error'=>$v['code']===200?'':'GitHub returned HTTP '.$v['code'].($v['code']===404?' (repository/branch not found or private – add a GitHub token)':''),'latest'=>$v['data']['version']??null,'notes'=>$v['data']['notes']??[],'date'=>$v['data']['date']??'','commits'=>[]];
    if($cm['code']===200&&is_array($cm['data']))foreach($cm['data'] as $x)$out['commits'][]=['sha'=>substr($x['sha'],0,7),'msg'=>strtok($x['commit']['message'],"\n"),'date'=>$x['commit']['author']['date'],'author'=>$x['commit']['author']['name'],'url'=>$x['html_url']];
    $out['available']=$out['latest']&&version_compare($out['latest'],APP_VERSION,'>');
    Settings::set('update_cache',je($out));
    return $out;}
  static function apply(){
    if(!class_exists('ZipArchive'))throw new Exception('PHP zip extension is required for one-click updates.');
    @set_time_limit(600);
    $tmp=STORAGE.'/tmp/upd-'.date('YmdHis');@mkdir($tmp,0755,true);
    $zipf=$tmp.'/src.zip';
    $r=http('https://api.github.com/repos/'.self::repo().'/zipball/'.rawurlencode(self::branch()),['headers'=>self::h(),'timeout'=>300]);
    if($r['code']!==200||strlen($r['body'])<1000)throw new Exception('Download failed (HTTP '.$r['code'].')');
    file_put_contents($zipf,$r['body']);
    return self::install($zipf,$tmp);}
  static function install($zipf,$tmp){
    $z=new ZipArchive();
    if($z->open($zipf)!==true)throw new Exception('Downloaded file is not a valid zip');
    $z->extractTo($tmp.'/x');$z->close();
    $dirs=glob($tmp.'/x/*',GLOB_ONLYDIR);
    if(!$dirs||!is_file($dirs[0].'/index.php')||!is_file($dirs[0].'/app/bootstrap.php'))throw new Exception('Package layout not recognised');
    $src=$dirs[0];
    $bk=new ZipArchive();$bkf=STORAGE.'/backups/before-'.APP_VERSION.'-'.date('YmdHis').'.zip';
    $bk->open($bkf,ZipArchive::CREATE);
    $n=0;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $f){
      $rel=str_replace('\\','/',substr($f->getPathname(),strlen($src)+1));
      $top=explode('/',$rel)[0];
      if(in_array($top,self::KEEP,true)||in_array($rel,self::KEEP,true))continue;
      $dst=ROOT.'/'.$rel;
      if($f->isDir()){if(!is_dir($dst))@mkdir($dst,0755,true);continue;}
      if(is_file($dst)){if(md5_file($dst)===md5_file($f->getPathname()))continue;$bk->addFile($dst,$rel);}
      if(!@copy($f->getPathname(),$dst))throw new Exception('Cannot write '.$rel.' – check file permissions');
      $n++;}
    $bk->close();
    self::rrm($tmp);
    if(function_exists('opcache_reset'))@opcache_reset();
    Settings::set('update_cache','');
    return $n;}
  static function rrm($d){
    if(!is_dir($d))return;
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f)$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());
    @rmdir($d);}}
