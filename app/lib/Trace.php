<?php
// Step-by-step log of a lead's background work (audit, report, sending): what ran, how long, AI tokens and cost, errors.
// Shown live in "View process". All calls are no-ops outside a lead job.
final class Trace{
  static $lead=0,$job=0,$cur=0,$t0=0.0,$msg='';
  static function begin($lead,$job,$title){self::$lead=(int)$lead;self::$job=(int)$job;self::$cur=0;self::$msg='';self::step($title,'start');self::close('ok');}
  static function end($st='ok',$detail=null){self::close($st,$detail);self::$lead=0;self::$job=0;}
  // Start a step (the open one is closed as done)
  static function step($msg,$kind='step',$meta=[]){
    if(!self::$lead)return 0;
    self::close('ok');self::$t0=microtime(true);self::$msg=$msg;
    return self::$cur=DB::insert('job_steps',['lead_id'=>self::$lead,'job_id'=>self::$job,'kind'=>$kind,'step'=>mb_substr($msg,0,190)]+$meta);}
  static function close($st='ok',$detail=null,$meta=[]){
    if(!self::$cur)return;
    DB::update('job_steps',['status'=>$st,'ms'=>(int)round((microtime(true)-self::$t0)*1000)]+($detail!==null?['detail'=>mb_substr((string)$detail,0,2000)]:[])+$meta,'id=?',[self::$cur]);
    self::$cur=0;}
  static function note($detail){if(self::$cur)DB::update('job_steps',['detail'=>mb_substr((string)$detail,0,2000)],'id=?',[self::$cur]);}
  // Heartbeat while waiting on a slow request: shows the wait live, keeps the job alive for the stuck-job reaper,
  // and returns true when the user pressed Abort (the request is then cancelled at once)
  static function tick($label){
    if(!self::$lead)return null;
    $lead=self::$lead;$job=self::$job;$sid=self::$cur;$t0=microtime(true);$last=$t0;
    return function()use($label,$lead,$job,$sid,$t0,&$last){
      $now=microtime(true);if($now-$last<4)return false;$last=$now;$s=(int)($now-$t0);
      $w=$s<60?$s.'s':floor($s/60).'m '.($s%60).'s';
      try{
        DB::update('leads',['progress_msg'=>mb_substr($label.' · '.$w,0,180)],'id=?',[$lead]);
        if($sid)DB::update('job_steps',['detail'=>'Waiting for the AI to answer · '.$w.($s>120?' – slower than usual; the provider may be busy':'')],'id=?',[$sid]);
        if($job)DB::q("UPDATE jobs SET locked_at=NOW() WHERE id=? AND status='running'",[$job]);
        return (bool)DB::val('SELECT abort_flag FROM leads WHERE id=?',[$lead]);}
      catch(Throwable $e){return false;}};}
  // Steps of the latest runs of a lead + totals
  static function view($lid,$limit=150){
    $rows=array_reverse(DB::all('SELECT id,job_id,kind,step,status,detail,model,tokens_in,tokens_out,cost,ms,UNIX_TIMESTAMP(created_at) at FROM job_steps WHERE lead_id=? ORDER BY id DESC LIMIT '.(int)$limit,[$lid]));
    $now=time();$t=['in'=>0,'out'=>0,'cost'=>0.0,'ai_ms'=>0,'ai'=>0];
    foreach($rows as &$r){$r['at']=(int)$r['at'];if($r['status']==='run')$r['ms']=($now-$r['at'])*1000;foreach(['tokens_in','tokens_out','ms','id','job_id'] as $k)$r[$k]=(int)$r[$k];$r['cost']=(float)$r['cost'];
      if($r['kind']==='ai'){$t['in']+=$r['tokens_in'];$t['out']+=$r['tokens_out'];$t['cost']+=$r['cost'];$t['ai_ms']+=$r['ms'];$t['ai']++;}}
    return ['steps'=>$rows,'totals'=>$t,'now'=>$now];}}
