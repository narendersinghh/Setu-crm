<?php
final class Aborted extends Exception{}
final class Queue{
  const LEAD_JOBS="'enrich','audit','report','contact'";
  static function push($type,$ref,$payload=[],$at=null){
    return DB::insert('jobs',['type'=>$type,'ref_id'=>(int)$ref,'payload'=>je($payload),'run_after'=>$at?:date('Y-m-d H:i:s')]);}
  static function due(){return (int)DB::val("SELECT COUNT(*) FROM jobs WHERE status='pending' AND run_after<=NOW()");}
  // Progress shown on the lead row and in the activity panel
  static function progress($lid,$pct,$msg){
    DB::update('leads',['progress'=>max(0,min(100,(int)$pct)),'progress_msg'=>mb_substr($msg,0,180)],'id=?',[$lid]);
    if((int)DB::val('SELECT abort_flag FROM leads WHERE id=?',[$lid]))throw new Aborted('Stopped by user');}
  // Stop everything for these leads: pending jobs are cancelled, a running job stops at its next step
  static function abort($ids){
    [$in,$v]=DB::in($ids);if(!$v)return 0;
    DB::q("UPDATE jobs SET status='cancelled',error='Stopped by user' WHERE status='pending' AND type IN (".self::LEAD_JOBS.") AND ref_id IN ($in)",$v);
    DB::q("UPDATE jobs SET status='cancelled',error='Stopped by user' WHERE status='running' AND type IN (".self::LEAD_JOBS.") AND ref_id IN ($in)",$v);
    $n=0;
    foreach(DB::all("SELECT id,stage FROM leads WHERE id IN ($in)",$v) as $l){
      if(!in_array($l['stage'],['queued','auditing','reporting','contacting'],true))continue;
      $back=DB::val('SELECT COUNT(*) FROM reports WHERE lead_id=?',[$l['id']])?'report_ready':'new';
      DB::update('leads',['abort_flag'=>1,'stage'=>$back,'progress'=>0,'progress_msg'=>'Stopped'],'id=?',[$l['id']]);
      event($l['id'],'error','Process stopped manually');$n++;}
    return $n;}
  static function work($budget=null){
    $budget=$budget?:(int)setting('queue_budget','20');
    @set_time_limit(max(300,$budget+280));
    @ignore_user_abort(true);
    $t0=microtime(true);$n=0;
    self::reapStuck();
    while(microtime(true)-$t0<$budget){
      $j=DB::row("SELECT * FROM jobs WHERE status='pending' AND run_after<=NOW() ORDER BY FIELD(type,'search','enrich','auto','audit','report','contact'),id LIMIT 1");
      if(!$j)break;
      if(!DB::q("UPDATE jobs SET status='running',locked_at=NOW(),attempts=attempts+1 WHERE id=? AND status='pending'",[$j['id']])->rowCount())continue;
      try{self::handle($j);DB::q("UPDATE jobs SET status='done',error=NULL WHERE id=? AND status='running'",[$j['id']]);}
      catch(Aborted $e){DB::q("UPDATE jobs SET status='cancelled',error=? WHERE id=?",[$e->getMessage(),$j['id']]);}
      catch(Throwable $e){self::fail($j,$e);}
      $n++;}
    return $n;}
  // Jobs killed by the host (timeout) stay "running": retry them, then fail with a clear message
  static function reapStuck(){
    $min=max(3,(int)setting('job_timeout','6'));
    foreach(DB::all("SELECT * FROM jobs WHERE status='running' AND locked_at<NOW()-INTERVAL $min MINUTE") as $j){
      $msg='Timed out – the server stopped this step after '.$min.' min (often a slow AI model or a host time limit).';
      if($j['attempts']<2&&$j['type']!=='search'){DB::q("UPDATE jobs SET status='pending',locked_at=NULL,error=? WHERE id=?",[$msg.' Retrying…',$j['id']]);continue;}
      self::fail($j,new Exception($msg.' Try again, or choose a faster AI model in AI Connection.'),true);}}
  private static function fail($j,$e,$final=false){
    $msg=$e->getMessage();
    logmsg("Job {$j['id']} {$j['type']}#{$j['ref_id']}: $msg");
    $att=(int)$j['attempts'];
    if(!$final&&$att<2&&!in_array($j['type'],['search','auto'],true)){
      DB::q("UPDATE jobs SET status='pending',locked_at=NULL,error=?,run_after=NOW()+INTERVAL 1 MINUTE WHERE id=?",[$msg,$j['id']]);
      if($j['type']!=='enrich')DB::update('leads',['progress_msg'=>'Retrying after error: '.mb_substr($msg,0,140)],'id=?',[$j['ref_id']]);
      return;}
    DB::q("UPDATE jobs SET status='failed',error=? WHERE id=?",[$msg,$j['id']]);
    if($j['type']==='search')DB::update('campaigns',['status'=>'error','error'=>$msg],'id=?',[$j['ref_id']]);
    elseif($j['type']==='auto')Automation::failed($j['ref_id'],$msg);
    elseif($j['type']!=='enrich'){DB::update('leads',['stage'=>'failed','last_error'=>$msg,'progress_msg'=>'Failed'],'id=?',[$j['ref_id']]);event($j['ref_id'],'error',ucfirst($j['type']).' failed: '.$msg);}}
  private static function handle($j){
    $p=jd($j['payload']);$id=(int)$j['ref_id'];
    switch($j['type']){
      case 'search':self::search($id,$p);break;
      case 'enrich':self::enrich($id);break;
      case 'audit':self::audit($id,$p);break;
      case 'report':self::report($id,$p);break;
      case 'contact':Outreach::contact($id,$p);break;
      case 'auto':Automation::step($id);break;
      case 'call':Voice::call($id,$p);break;}}
  private static function search($cid,$p){
    $c=DB::row('SELECT * FROM campaigns WHERE id=?',[$cid]);
    if(!$c||$c['status']==='stopped')return;
    $q=trim($c['keyword'].($c['location']?' in '.$c['location']:''));
    $res=Maps::search($q,$p['next']??null,$c['source']);
    $have=(int)$c['found'];
    foreach($res['items'] as $it){
      if($have>=$c['limit_n'])break;
      if(DB::val('SELECT id FROM leads WHERE campaign_id=? AND place_id=?',[$cid,$it['place_id']]))continue;
      $have++;
      $lid=DB::insert('leads',['campaign_id'=>$cid,'position'=>$have,'place_id'=>$it['place_id'],'name'=>mb_substr($it['name'],0,250),'category'=>mb_substr($it['category'],0,180),'address'=>mb_substr($it['address'],0,490),'city'=>mb_substr($it['city']?:$c['location'],0,110),'phone'=>$it['phone'],'phone_source'=>$it['phone']?'maps':'','website'=>mb_substr($it['website'],0,490),'rating'=>$it['rating'],'reviews'=>(int)$it['reviews'],'maps_url'=>mb_substr($it['maps_url'],0,490),'lat'=>$it['lat'],'lng'=>$it['lng'],'raw'=>je($it['raw']),'source'=>'maps','source_detail'=>mb_substr($q,0,180),'token'=>token(16),'last_activity'=>date('Y-m-d H:i:s')]);
      event($lid,'found','Found on Google Maps at position #'.$have.' for "'.$q.'"','maps');
      if($it['website'])self::push('enrich',$lid);}
    DB::update('campaigns',['found'=>$have],'id=?',[$cid]);
    if($have<$c['limit_n']&&!empty($res['next'])&&$res['items'])self::push('search',$cid,['next'=>$res['next']],date('Y-m-d H:i:s',time()+($c['source']==='places'?2:0)));
    else DB::update('campaigns',['status'=>'ready'],'id=?',[$cid]);}
  static function enrich($lid){
    $l=DB::row('SELECT * FROM leads WHERE id=?',[$lid]);
    if(!$l||!$l['website'])return;
    $c=Scraper::contacts($l['website']);
    $u=['socials'=>je($c['socials']+($c['whatsapp']?['whatsapp'=>$c['whatsapp']]:[]))];
    $found=[];
    if(!$l['phone']&&$c['phones']){$u['phone']=mb_substr($c['phones'][0],0,40);$u['phone_source']='website';$found[]='phone '.$u['phone'];}
    if(!$l['email']&&$c['emails']){$u['email']=mb_substr($c['emails'][0],0,190);$found[]='email '.$u['email'];}
    DB::update('leads',$u,'id=?',[$lid]);
    event($lid,'enriched',$found?'Found on website: '.implode(', ',$found):'Website scanned – no extra contact details found','web');}
  private static function audit($lid,$p){
    $l=DB::row('SELECT l.*,COALESCE(c.keyword,l.category) keyword FROM leads l LEFT JOIN campaigns c ON c.id=l.campaign_id WHERE l.id=?',[$lid]);
    if(!$l)return;
    DB::update('leads',['stage'=>'auditing','last_error'=>null],'id=?',[$lid]);
    self::progress($lid,5,'Starting audit');
    if($l['website']&&!$l['email']){self::progress($lid,8,'Looking for email & phone on the website');self::enrich($lid);}
    $a=jd($l['audit']);
    if($l['website']){$a['website']=Auditor::website($l['website'],fn($pct,$m)=>self::progress($lid,10+$pct*0.4,$m),$l);}
    self::progress($lid,52,'Auditing Google Maps profile');
    $comp=$l['campaign_id']?DB::all('SELECT reviews,rating FROM leads WHERE campaign_id=? AND id<>? ORDER BY position LIMIT 3',[$l['campaign_id'],$lid]):[];
    $a['map']=Auditor::map($l,$comp);
    DB::update('leads',['audit'=>je($a),'web_score'=>$l['website']?$a['website']['score']:null,'map_score'=>$a['map']['score']],'id=?',[$lid]);
    event($lid,'audited',($l['website']?'Website score '.$a['website']['score'].'/100 · ':'No website · ').'Google Maps score '.$a['map']['score'].'/100');
    self::progress($lid,58,'Audit complete – queued for AI report');
    self::push('report',$lid,$p);}
  private static function report($lid,$p){
    DB::update('leads',['stage'=>'reporting'],'id=?',[$lid]);
    Report::generate($lid,$p,fn($pct,$m)=>self::progress($lid,$pct,$m));
    DB::update('leads',['progress'=>100,'progress_msg'=>'Report ready'],'id=?',[$lid]);
    stage($lid,'report_ready','PDF report generated');
    Automation::fire('report_ready',$lid);
    if(!empty($p['auto']))Outreach::schedule($lid);}}
