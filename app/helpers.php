<?php
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function setting($k,$d=''){return Settings::get($k,$d);}
function cfg($k,$d=null){return $GLOBALS['CFG'][$k]??$d;}
// Public address of the app: Settings → Company → App address, else config.php "url", else the current host
function app_url(){static $u=null;if($u===null){try{$u=(string)setting('app_url');}catch(Throwable $e){$u='';}$u=rtrim($u?:(string)cfg('url',''),'/');}return $u;}
function base_url($p=''){
  $u=app_url();
  if($u===''&&isset($_SERVER['HTTP_HOST'])){$u=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST'].rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/');}
  return $u.'/'.ltrim($p,'/');}
function url($r,$q=[]){return 'index.php?'.http_build_query(['r'=>$r]+$q);}
function redirect($u){header('Location: '.$u);exit;}
function back($fallback='index.php'){redirect($_SERVER['HTTP_REFERER']??$fallback);}
function flash($msg=null,$type='ok'){
  if($msg===null){$f=$_SESSION['flash']??[];unset($_SESSION['flash']);return $f;}
  $_SESSION['flash'][]=[$type,$msg];}
function csrf(){if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(16));return $_SESSION['csrf'];}
function csrf_field(){return '<input type="hidden" name="_csrf" value="'.csrf().'">';}
function csrf_check(){
  $t=$_POST['_csrf']??$_SERVER['HTTP_X_CSRF']??'';
  if(!hash_equals(csrf(),(string)$t)){http_response_code(419);exit('Session expired. Go back and refresh the page.');}}
function is_post(){return ($_SERVER['REQUEST_METHOD']??'')==='POST';}
function json_out($d,$code=200){http_response_code($code);header('Content-Type: application/json');echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function user(){static $u=false;if($u===false||($u&&($u['id']??0)!=($_SESSION['uid']??0)))$u=($_SESSION['uid']??null)?DB::row('SELECT id,name,email,role,phone FROM users WHERE id=? AND active=1',[$_SESSION['uid']]):null;return $u;}
// Roles: super (everything incl. modules), admin (manager – everything except modules & super users), sales (own + unassigned leads, appointments)
function roles(){return ['super'=>['Super admin','purple','Full control: modules, all settings, every user and every lead.'],'admin'=>['Admin / Manager','blue','Runs the team: all leads, prospecting, automations, templates and settings. Cannot switch modules or manage super admins.'],'sales'=>['Sales person','green','Works their own leads and unassigned leads, books appointments, logs calls and notes.']];}
function role(){return user()['role']??'sales';}
function is_super(){return role()==='super';}
function can($p){$r=role();if($r==='super')return true;if($r==='admin')return !in_array($p,['modules','super_users'],true);return in_array($p,['dashboard','leads','appointments','account'],true);}
// Modules: CRM is always on; Prospecting can be switched off by a super admin
function modules(){return ['crm'=>['CRM','Leads, pipeline board, lead capture from forms & ads, custom fields, appointments with Google Calendar, automations, WhatsApp, email and AI calls.','users','green',true],'prospecting'=>['Prospecting','Google Maps scraper, website & Maps audits, AI growth reports as branded PDFs, outreach by email & WhatsApp with tracking.','map','orange',false]];}
function mod($m){return $m==='crm'||setting('mod_'.$m,'1')==='1';}
// SQL filter so sales people only see their own + unassigned leads
function scope($a='l'){return role()==='sales'?' AND ('.$a.'.assigned_to='.(int)user()['id'].' OR '.$a.'.assigned_to IS NULL)':'';}
function lead_ok($l){return $l&&(role()!=='sales'||($l['is_lead']&&(!$l['assigned_to']||(int)$l['assigned_to']===(int)user()['id'])));}
function rbadge($r){$a=roles()[$r]??[ucfirst((string)$r),'gray'];return '<span class="bdg bdg-'.$a[1].'">'.e($a[0]).'</span>';}
// Stat card used across pages
function kpi($label,$value,$ic,$c,$foot='',$href=''){return '<'.($href?'a href="'.e($href).'"':'div').' class="stat st-'.$c.'"><span class="st-i">'.icon($ic).'</span><span class="st-l">'.e($label).'</span><b class="st-v">'.$value.'</b>'.($foot!==''?'<small class="st-f">'.$foot.'</small>':'').'</'.($href?'a':'div').'>';}
// How audit reports reach the business: link (web page), pdf (attachment) or both
function report_mode(){$m=setting('report_delivery','both');return in_array($m,['link','pdf','both'],true)?$m:'both';}
function denied($msg='You do not have access to that page.'){flash($msg,'err');redirect(url('dashboard'));}
function money($n,$dec=0){return setting('currency_symbol','₹').number_format((float)$n,$dec);}
function ago($ts){
  if(!$ts)return '—';
  $d=time()-strtotime($ts);
  if($d<60)return 'just now';
  if($d<3600)return floor($d/60).'m ago';
  if($d<86400)return floor($d/3600).'h ago';
  if($d<604800)return floor($d/86400).'d ago';
  return date('d M Y',strtotime($ts));}
function jd($s){$d=json_decode((string)$s,true);return is_array($d)?$d:[];}
function je($d){return json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function tpl($s,$vars){return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i',fn($m)=>array_key_exists($m[1],$vars)?(string)$vars[$m[1]]:$m[0],(string)$s);}
function token($n=20){return bin2hex(random_bytes($n));}
function logmsg($msg){@file_put_contents(STORAGE.'/logs/app-'.date('Y-m').'-'.substr(hash('sha256','log'.cfg('app_key','')),0,10).'.log','['.date('Y-m-d H:i:s').'] '.$msg."\n",FILE_APPEND);}
function event($lead,$type,$msg,$channel=null,$meta=null){
  DB::insert('events',['lead_id'=>(int)$lead,'type'=>$type,'channel'=>$channel,'message'=>mb_substr($msg,0,1000),'meta'=>$meta?je($meta):null]);
  DB::q('UPDATE leads SET last_activity=NOW() WHERE id=?',[(int)$lead]);}
function stage($lead,$s,$msg=null){DB::update('leads',['stage'=>$s],'id=?',[(int)$lead]);if($msg)event($lead,'stage',$msg,null,['stage'=>$s]);}
function stages(){return ['new'=>['New','gray'],'queued'=>['Queued','blue'],'auditing'=>['Auditing','blue'],'reporting'=>['Writing report','purple'],'report_ready'=>['Report ready','gold'],'contacting'=>['Sending','blue'],'contacted'=>['Contacted','teal'],'opened'=>['Opened','teal'],'replied'=>['Replied','green'],'won'=>['Won','green'],'lost'=>['Not interested','red'],'failed'=>['Failed','red'],'skipped'=>['Skipped','gray']];}
function badge($s){$a=stages()[$s]??[ucfirst((string)$s),'gray'];return '<span class="bdg bdg-'.$a[1].'">'.e($a[0]).'</span>';}
function cbadge($s){$m=['searching'=>['Searching','blue'],'ready'=>['Ready','green'],'error'=>['Error','red'],'stopped'=>['Stopped','gray']];$a=$m[$s]??[ucfirst($s),'gray'];return '<span class="bdg bdg-'.$a[1].'">'.e($a[0]).'</span>';}
function chbadge($s){$m=['sent'=>'teal','delivered'=>'teal','read'=>'green','opened'=>'green','failed'=>'red','manual'=>'gold','queued'=>'blue'];return $s?'<span class="bdg bdg-'.($m[$s]??'gray').'">'.e(ucfirst($s)).'</span>':'<span class="muted">—</span>';}
function phone_norm($p){
  $p=trim((string)$p);
  if($p==='')return '';
  $plus=$p[0]==='+';
  $d=preg_replace('/\D+/','',$p);
  if($d==='')return '';
  $cc=preg_replace('/\D+/','',setting('default_country_code','91'));
  if($plus)return $d;
  if(strncmp($d,'00',2)===0)return substr($d,2);
  $d=ltrim($d,'0');
  if(strlen($d)<=10)return $cc.$d;
  return $d;}
// Public IP for a URL's host, or null for private / reserved / loopback / metadata addresses (SSRF guard)
function public_ip($url){
  $p=parse_url((string)$url);if(!in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host']))return null;
  $h=trim($p['host'],'[]');$ips=filter_var($h,FILTER_VALIDATE_IP)?[$h]:(@gethostbynamel($h)?:[]);
  foreach($ips as $ip)if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return $ip;
  return null;}
// Only http(s) links are ever printed into href attributes
// Simple per-key rate limit (public forms, booking, login): false when more than $max hits within $min minutes
function throttle($key,$max,$min=15){$k=substr($key,0,45);$n=(int)DB::val('SELECT n FROM login_fails WHERE ip=? AND last>NOW()-INTERVAL '.(int)$min.' MINUTE',[$k]);if($n>=$max)return false;DB::q('INSERT INTO login_fails (ip,n,last) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE n=IF(last<NOW()-INTERVAL '.(int)$min.' MINUTE,1,n+1),last=NOW()',[$k]);return true;}
// Check many links at once (parallel). Returns url => true when the page opens. Missing pages (404/410),
// dead domains, server errors and "page not found" pages count as broken; 401/403/429 (bot blocking) count as working.
function links_ok($urls){
  $urls=array_values(array_unique(array_filter(array_map('trim',(array)$urls))));$out=[];$mh=curl_multi_init();$hs=[];$buf=[];
  foreach($urls as $i=>$u){
    if(!public_ip($u)){$out[$u]=false;continue;}
    $buf[$i]='';$ch=curl_init($u);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>6,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,application/pdf;q=0.9,*/*;q=0.8','Accept-Language: en'],
      CURLOPT_WRITEFUNCTION=>function($c,$d)use(&$buf,$i){$buf[$i].=$d;return strlen($buf[$i])>60000?0:strlen($d);}]);
    curl_multi_add_handle($mh,$ch);$hs[$i]=$ch;}
  do{$st=curl_multi_exec($mh,$run);if($run)curl_multi_select($mh,1);}while($run&&$st===CURLM_OK);
  foreach($hs as $i=>$ch){
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$b=$buf[$i];
    $soft=preg_match('~<title[^>]*>[^<]{0,120}(404|not found|page (?:does not|doesn.t) exist|page unavailable|no longer available)~i',$b);
    $out[$urls[$i]]=(($code>=200&&$code<400)&&!$soft)||in_array($code,[401,403,429],true);
    curl_multi_remove_handle($mh,$ch);curl_close($ch);}
  curl_multi_close($mh);
  return $out;}
function safe_url($u){$u=trim((string)$u);return preg_match('~^https?://~i',$u)?$u:'#';}
function http($url,$o=[]){
  // Fetching a third-party website (audits, contact scan): every hop must resolve to a public IP; DNS is pinned per hop
  if(!empty($o['public'])){
    unset($o['public']);$t=0;
    for($i=0;$i<6;$i++){
      $ip=public_ip($url);if(!$ip)return ['code'=>0,'body'=>'','error'=>'Blocked: not a public web address','url'=>$url,'time'=>$t,'ttfb'=>0,'size'=>0,'type'=>''];
      $pu=parse_url($url);$port=$pu['port']??(strtolower($pu['scheme'])==='https'?443:80);
      $r=http($url,$o+['follow'=>false,'resolve'=>[$pu['host'].':'.$port.':'.(strpos($ip,':')!==false?'['.$ip.']':$ip)]]);$t+=$r['time'];
      if($r['code']<300||$r['code']>=400||!$r['location'])break;
      $url=Scraper::abs($r['location'],$url)?:$r['location'];}
    $r['time']=$t;return $r;}
  $ch=curl_init($url);
  $h=$o['headers']??[];
  $opt=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>$o['follow']??true,CURLOPT_MAXREDIRS=>5,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_TIMEOUT=>$o['timeout']??30,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>$o['ua']??'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',CURLOPT_SSL_VERIFYPEER=>$o['verify']??true];
  if(isset($o['json'])){$o['body']=je($o['json']);$h[]='Content-Type: application/json';}
  if(isset($o['body'])){$opt[CURLOPT_POSTFIELDS]=$o['body'];$opt[CURLOPT_CUSTOMREQUEST]=$o['method']??'POST';}
  elseif(isset($o['method']))$opt[CURLOPT_CUSTOMREQUEST]=$o['method'];
  if(!empty($o['nobody']))$opt[CURLOPT_NOBODY]=true;
  if(!empty($o['resolve']))$opt[CURLOPT_RESOLVE]=$o['resolve'];
  // Heartbeat while waiting (e.g. slow AI): the callable returns true to cancel the request
  if(!empty($o['tick'])){$tk=$o['tick'];$opt[CURLOPT_NOPROGRESS]=false;$opt[CURLOPT_XFERINFOFUNCTION]=fn($c,$a,$b,$x,$y)=>$tk()?1:0;}
  if(isset($o['max'])){$max=$o['max'];$buf='';$opt[CURLOPT_WRITEFUNCTION]=function($c,$d)use(&$buf,$max){$buf.=$d;return strlen($buf)>$max?0:strlen($d);};}
  $opt[CURLOPT_HTTPHEADER]=$h;
  curl_setopt_array($ch,$opt);
  $body=curl_exec($ch);
  if(isset($o['max']))$body=$buf;
  $i=curl_getinfo($ch);
  $en=curl_errno($ch);$err=$en&&!(isset($o['max'])&&$en===CURLE_WRITE_ERROR)?curl_error($ch):'';
  curl_close($ch);
  return ['code'=>(int)$i['http_code'],'body'=>(string)$body,'error'=>$err,'url'=>$i['url'],'location'=>(string)($i['redirect_url']??''),'aborted'=>$en===CURLE_ABORTED_BY_CALLBACK,'time'=>$i['total_time'],'ttfb'=>$i['starttransfer_time'],'size'=>strlen((string)$body),'type'=>(string)$i['content_type']];}
function http_json($url,$o=[]){$r=http($url,$o);$r['data']=json_decode($r['body'],true);return $r;}
function icon($n,$c=''){
  static $I=['grid'=>'<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>','search'=>'<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>','users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>','file'=>'<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M16 13H8M16 17H8M10 9H8"/>','msg'=>'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>','sliders'=>'<path d="M20 7h-9M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>','spark'=>'<path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.14-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.14a.5.5 0 0 1 .96 0L14.06 8.5a2 2 0 0 0 1.44 1.44l6.14 1.58a.5.5 0 0 1 0 .96L15.5 14.06a2 2 0 0 0-1.44 1.44l-1.58 6.14a.5.5 0 0 1-.96 0z"/>','refresh'=>'<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8M21 3v5h-5M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16M8 16H3v5"/>','logout'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>','play'=>'<path d="M6 3l14 9-14 9z"/>','mail'=>'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>','phone'=>'<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>','globe'=>'<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20M2 12h20"/>','pin'=>'<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>','star'=>'<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>','plus'=>'<path d="M5 12h14M12 5v14"/>','trash'=>'<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>','edit'=>'<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>','download'=>'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>','eye'=>'<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>','check'=>'<path d="M20 6 9 17l-5-5"/>','x'=>'<path d="M18 6 6 18M6 6l12 12"/>','menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>','wa'=>'<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>','clock'=>'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>','list'=>'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>','book'=>'<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>','copy'=>'<rect x="8" y="8" width="14" height="14" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>','zap'=>'<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>','send'=>'<path d="m22 2-7 20-4-9-9-4ZM22 2 11 13"/>','upload'=>'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>','activity'=>'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>','link'=>'<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>','alert'=>'<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3ZM12 9v4M12 17h.01"/>','user'=>'<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>','cal'=>'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>','bot'=>'<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4M8 16h.01M16 16h.01"/>','plug'=>'<path d="M12 22v-5M9 8V2M15 8V2M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>','db'=>'<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5M3 12a9 3 0 0 0 18 0"/>','map'=>'<path d="M14.1 6 9 3 3 6v15l6-3 5.1 3 5.9-3V3z"/><path d="M9 3v15M15 6v15"/>','target'=>'<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>','kanban'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 7v7M12 7v4M16 7v9"/>','stop'=>'<rect x="5" y="5" width="14" height="14" rx="2"/>','mic'=>'<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4"/>','image'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>','filter'=>'<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>','tag'=>'<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z"/><circle cx="7.5" cy="7.5" r="1.5"/>','shield'=>'<path d="M20 13c0 5-3.5 7.5-7.7 9a1 1 0 0 1-.7 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z"/>','share'=>'<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>','layers'=>'<path d="m12 2 10 5-10 5L2 7zM2 17l10 5 10-5M2 12l10 5 10-5"/>'];
  return '<svg class="ic '.$c.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.($I[$n]??'').'</svg>';}
function require_login(){if(!user()){redirect('index.php?r=login');}}
function lead_vars($lead,$extra=[]){
  $r=DB::row('SELECT token FROM reports WHERE lead_id=? ORDER BY id DESC LIMIT 1',[$lead['id']]);
  $cn=($lead['contact_name']??'')?:'Sir/Madam';
  return $extra+['contact_name'=>$cn,'first_name'=>explode(' ',$cn)[0],'booking_link'=>Booking::link($lead),'service'=>$lead['service']??'','status'=>crm_statuses()[$lead['status']??'new'][0]??'','business_name'=>$lead['name'],'category'=>$lead['category'],'address'=>$lead['address'],'city'=>$lead['city']?:'','phone'=>$lead['phone'],'email'=>$lead['email'],'website'=>$lead['website'],'rating'=>$lead['rating'],'reviews'=>$lead['reviews'],'keyword'=>$lead['keyword']??'','agency_name'=>setting('company_name'),'agency_phone'=>setting('company_phone'),'agency_email'=>setting('company_email'),'agency_website'=>setting('company_website'),'agency_address'=>setting('company_address'),'sender_name'=>setting('sender_name',setting('company_name')),'report_link'=>$r?base_url('report.php?t='.$r['token']):'','currency'=>setting('currency','INR'),'currency_symbol'=>setting('currency_symbol','₹'),'yearly_fee'=>number_format((float)setting('yearly_fee','60000')),'website_fee'=>number_format((float)setting('website_fee','25000'))];}
function crm_statuses(){return ['new'=>['New','blue'],'contacted'=>['Contacted','teal'],'qualified'=>['Qualified','purple'],'meeting'=>['Meeting booked','orange'],'proposal'=>['Proposal sent','gold'],'won'=>['Won','green'],'lost'=>['Lost','red']];}
function sbadge($s){$a=crm_statuses()[$s]??[ucfirst((string)$s),'gray'];return '<span class="bdg bdg-'.$a[1].'">'.e($a[0]).'</span>';}
function lead_sources(){return ['maps'=>['Map scraper','orange','map'],'manual'=>['Manual','gray','edit'],'website'=>['Website form','blue','globe'],'facebook'=>['Meta / Facebook ads','purple','target'],'google'=>['Google Ads','green','zap'],'webhook'=>['Webhook / API','teal','plug'],'import'=>['CSV import','gray','upload'],'booking'=>['Booking page','orange','cal']];}
function srcbadge($s){$a=lead_sources()[$s]??[ucfirst((string)$s),'gray','link'];return '<span class="src-b src-'.$a[1].'">'.icon($a[2]).e($a[0]).'</span>';}
function pbar($pct,$msg=''){$pct=(int)$pct;return '<div class="pb" title="'.e($msg).'"><i style="width:'.$pct.'%"></i></div><small class="pbm">'.$pct.'% · '.e(mb_strimwidth($msg,0,46,'…')).'</small>';}
// Settings hub: top module tabs → left menu items [key,label,icon,url]. Pages like AI, Templates, Team, Usage, Updates live here too.
function settings_map(){
  $s=fn($t)=>url('settings',['tab'=>$t]);
  $m=['general'=>['General','Company, brand & AI','grid','indigo',[['company','Company','grid',$s('company')],['branding','Brand & PDF design','image',$s('branding')],['ai','AI connection','spark',url('ai')]]],
  'channels'=>['Messaging','Email, WhatsApp, calls','msg','green',[['email','Email','mail',$s('email')],['whatsapp','WhatsApp','wa',$s('whatsapp')],['voice','AI voice calls','mic',$s('voice')]]],
  'prospecting'=>['Prospecting','Maps, audits, outreach','map','orange',[['data','Map scraper & audits','map',$s('data')],['pricing','Pricing & proposal','tag',$s('pricing')],['pdf','Report delivery','file',$s('pdf')],['outreach','Outreach sending','send',$s('outreach')],['templates','Templates & report design','layers',url('templates')]]],
  'crm'=>['CRM','Capture, fields, calendar','users','teal',[['capture','Lead capture','plug',$s('capture')],['fields','Custom fields','list',$s('fields')],['appointments','Appointments & calendar','cal',$s('appointments')]]],
  'system'=>['System','Scheduler & API usage','sliders','violet',[['scheduler','Background scheduler','zap',$s('scheduler')],['usage','API usage & costs','activity',url('usage')]]]];

  return $m;}
// Opens the settings shell (top tabs + left menu). Close with settings_end().
function settings_start($key){
  $M=settings_map();$grp='general';foreach($M as $g=>$x)foreach($x[4] as $i)if($i[0]===$key)$grp=$g;
  $o='<div class="mtabs">';foreach($M as $g=>[$lb,$ds,$ic,$c,$items]){$off=$g==='prospecting'&&!mod('prospecting');$o.='<a href="'.e($items[0][3]).'" class="c-'.$c.($grp===$g?' on':'').($off?' off':'').'"><span class="hi">'.icon($ic).'</span><span>'.e($lb).'<small>'.($off?'Module off':e($ds)).'</small></span></a>';}
  if(count($M[$grp][4])===1)return $o.'</div><div><div class="col">';
  $o.='</div><div class="sl"><nav class="snav c-'.$M[$grp][3].'"><div class="ng2">'.e($M[$grp][0]).'</div>';
  foreach($M[$grp][4] as [$k,$lb,$ic,$u])$o.='<a href="'.e($u).'" class="'.($k===$key?'on':'').'" title="'.e($lb).'">'.icon($ic).'<span>'.e($lb).'</span></a>';
  return $o.'</nav><div class="col">';}
function settings_end(){return '</div></div>';}
