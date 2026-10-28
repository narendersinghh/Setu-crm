<?php
final class Updater{
  const KEEP=['config.php','storage','.htaccess.local','.git','.user.ini','php.ini','error_log'];
  private static function h(){$h=['Accept: application/vnd.github+json','User-Agent: Setu-Updater'];if($t=setting('github_token'))$h[]='Authorization: Bearer '.$t;return $h;}
  const REPO='narendersinghh/Setu-crm';
  static function repo(){return trim(preg_replace('~^https?://github\.com/|\.git$~i','',setting('update_repo',self::REPO)),'/ ');}
  // release = install published GitHub releases only; commit = install the latest commit of the branch
  static function mode(){return setting('update_mode','release')==='commit'?'commit':'release';}
  static function branch(){return setting('update_branch','main');}
  static function check($force=false){
    $c=jd(setting('update_cache'));
    if(!$force&&$c&&time()-($c['at']??0)<6*3600&&($c['mode']??'')===self::mode()&&($c['repo']??'')===self::repo())return $c;
    $repo=self::repo();$br=self::branch();$mode=self::mode();$api='https://api.github.com/repos/'.$repo;
    $cm=http_json($api.'/commits?per_page=15&sha='.rawurlencode($br),['headers'=>self::h(),'timeout'=>20]);
    $out=['at'=>time(),'mode'=>$mode,'repo'=>$repo,'ok'=>false,'error'=>'','latest'=>null,'notes'=>[],'date'=>'','commits'=>[],'zip'=>'','sha'=>'','url'=>''];
    if($cm['code']===200&&is_array($cm['data']))foreach($cm['data'] as $x)$out['commits'][]=['sha'=>substr($x['sha'],0,7),'full'=>$x['sha'],'msg'=>strtok($x['commit']['message'],"\n"),'date'=>$x['commit']['author']['date'],'author'=>$x['commit']['author']['name'],'url'=>$x['html_url']];
    $err=fn($r,$what)=>'GitHub returned HTTP '.$r['code'].' for '.$what.($r['code']===404?' (repository/branch not found or private – add a GitHub token)':($r['code']===403?' (rate limit – try again later or add a token)':''));
    if($mode==='release'){
      $r=http_json($api.'/releases/latest',['headers'=>self::h(),'timeout'=>20]);
      if($r['code']===200&&!empty($r['data']['tag_name'])){$d=$r['data'];$out['ok']=true;$out['latest']=ltrim($d['tag_name'],'vV');$out['date']=substr($d['published_at']??'',0,10);$out['notes']=array_values(array_filter(array_map(fn($l)=>trim(ltrim(trim($l),'-*# ')),explode("\n",(string)($d['body']??'')))));$out['zip']=$d['zipball_url']??'';$out['url']=$d['html_url']??'';$out['name']=$d['name']??'';
        $out['available']=version_compare($out['latest'],APP_VERSION,'>');}
      else $out['error']=$r['code']===404&&$cm['code']===200?'No release published yet on '.$repo.'. Publish one on GitHub (Releases → Draft a new release, tag e.g. v1.1.0) or switch to "Latest commit".':$err($r,'the latest release');}
    else{
      if($cm['code']===200&&$out['commits']){
        $top=$out['commits'][0];$out['ok']=true;$out['sha']=$top['full'];$out['latest']=$top['sha'];$out['date']=substr($top['date'],0,10);$out['notes']=[$top['msg']];$out['zip']=$api.'/zipball/'.$top['full'];$out['url']=$top['url'];
        $cur=setting('installed_sha');$out['installed_sha']=$cur;
        $out['available']=$cur===''||strncmp($cur,$top['full'],7)!==0;
        // version.json on the branch (shown for information)
        $v=http_json($api.'/contents/version.json?ref='.rawurlencode($br),['headers'=>self::h(),'timeout'=>20]);
        if(isset($v['data']['content'])&&($vj=json_decode(base64_decode($v['data']['content']),true)))$out['version']=$vj['version']??null;}
      else $out['error']=$err($cm,'branch "'.$br.'"');}
    $out['available']=!empty($out['available']);
    Settings::set('update_cache',je($out));
    return $out;}
  static function apply(){
    if(!class_exists('ZipArchive'))throw new Exception('PHP zip extension is required for one-click updates.');
    @set_time_limit(600);
    $tmp=STORAGE.'/tmp/upd-'.date('YmdHis');@mkdir($tmp,0755,true);
    $zipf=$tmp.'/src.zip';
    $u=self::check(true);
    if(!$u['ok']||!$u['zip'])throw new Exception($u['error']?:'Nothing to install.');
    $r=http($u['zip'],['headers'=>self::h(),'timeout'=>300]);
    if($r['code']!==200||strlen($r['body'])<1000)throw new Exception('Download failed (HTTP '.$r['code'].')');
    file_put_contents($zipf,$r['body']);
    $n=self::install($zipf,$tmp);
    if($u['mode']==='commit')Settings::set('installed_sha',$u['sha']);else Settings::set('installed_sha','');
    return $n;}
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
