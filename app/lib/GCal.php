<?php
// Optional Google Calendar sync: busy times block booking slots; bookings become calendar events with a Meet link
final class GCal{
  static function ready(){return setting('gcal_client_id')!==''&&setting('gcal_refresh_token')!=='';}
  static function redirect(){return base_url('gcal.php');}
  static function authUrl(){
    $st=token(8);Settings::set('gcal_state',$st);
    return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>setting('gcal_client_id'),'redirect_uri'=>self::redirect(),'response_type'=>'code','scope'=>'https://www.googleapis.com/auth/calendar','access_type'=>'offline','prompt'=>'consent','state'=>$st]);}
  static function exchange($code){
    $r=http_json('https://oauth2.googleapis.com/token',['body'=>http_build_query(['code'=>$code,'client_id'=>setting('gcal_client_id'),'client_secret'=>setting('gcal_client_secret'),'redirect_uri'=>self::redirect(),'grant_type'=>'authorization_code']),'headers'=>['Content-Type: application/x-www-form-urlencoded'],'timeout'=>20]);
    if(empty($r['data']['refresh_token']))throw new Exception('Google did not return a refresh token: '.($r['data']['error_description']??$r['body']));
    Settings::set('gcal_refresh_token',$r['data']['refresh_token']);
    Settings::set('gcal_access',je(['t'=>$r['data']['access_token'],'exp'=>time()+(int)$r['data']['expires_in']-60]));
    $me=self::api('GET','users/me/calendarList/primary');Settings::set('gcal_account',$me['id']??'');}
  private static function token(){
    $a=jd(setting('gcal_access'));if(!empty($a['t'])&&$a['exp']>time())return $a['t'];
    $r=http_json('https://oauth2.googleapis.com/token',['body'=>http_build_query(['client_id'=>setting('gcal_client_id'),'client_secret'=>setting('gcal_client_secret'),'refresh_token'=>setting('gcal_refresh_token'),'grant_type'=>'refresh_token']),'headers'=>['Content-Type: application/x-www-form-urlencoded'],'timeout'=>20]);
    if(empty($r['data']['access_token']))throw new Exception('Google Calendar token refresh failed – reconnect in Settings → Appointments.');
    Settings::set('gcal_access',je(['t'=>$r['data']['access_token'],'exp'=>time()+(int)$r['data']['expires_in']-60]));
    return $r['data']['access_token'];}
  static function api($m,$path,$body=null){
    $o=['method'=>$m,'headers'=>['Authorization: Bearer '.self::token()],'timeout'=>20];if($body!==null)$o['json']=$body;
    $r=http_json('https://www.googleapis.com/calendar/v3/'.$path,$o);
    Usage::log('gcal',strtolower($m),1,0,0,null,$r['code']>=300);
    if($r['code']>=300)throw new Exception('Google Calendar: '.($r['data']['error']['message']??('HTTP '.$r['code'])));
    return $r['data']??[];}
  static function cal(){return rawurlencode(setting('gcal_calendar','primary'));}
  // Busy periods [[start,end],…] – cached 5 minutes to save API calls
  static function busy($from,$to){
    if(!self::ready())return [];
    $c=jd(setting('gcal_busy'));if(($c['at']??0)>time()-300&&($c['from']??0)<=$from&&($c['to']??0)>=$to)return $c['b'];
    try{
      $r=self::api('POST','freeBusy',['timeMin'=>date('c',$from),'timeMax'=>date('c',$to),'items'=>[['id'=>setting('gcal_calendar','primary')]]]);
      $b=[];foreach(($r['calendars'][setting('gcal_calendar','primary')]['busy']??[]) as $x)$b[]=[strtotime($x['start']),strtotime($x['end'])];
      Settings::set('gcal_busy',je(['at'=>time(),'from'=>$from,'to'=>$to,'b'=>$b]));return $b;}
    catch(Throwable $e){logmsg('GCal busy: '.$e->getMessage());return [];}}
  // Create the event (with Google Meet link when enabled) and store its id on the appointment
  static function push($apptId){
    if(!self::ready())return;
    $a=DB::row('SELECT a.*,l.name,l.contact_name,l.email,l.phone FROM appointments a LEFT JOIN leads l ON l.id=a.lead_id WHERE a.id=?',[$apptId]);if(!$a)return;
    try{
      $ev=['summary'=>$a['title'],'description'=>trim(($a['contact_name']?:$a['name'])."\nPhone: ".$a['phone']."\nEmail: ".$a['email']."\n\n".$a['notes']."\n\n".base_url('index.php?r=lead&id='.$a['lead_id'])),'start'=>['dateTime'=>date('c',strtotime($a['start_at']))],'end'=>['dateTime'=>date('c',strtotime($a['end_at']))],'location'=>$a['location']];
      if($a['email']&&setting('gcal_invite','1')==='1')$ev['attendees']=[['email'=>$a['email'],'displayName'=>$a['contact_name']?:$a['name']]];
      $meet=setting('gcal_meet','1')==='1';
      if($meet)$ev['conferenceData']=['createRequest'=>['requestId'=>token(8),'conferenceSolutionKey'=>['type'=>'hangoutsMeet']]];
      $r=self::api('POST','calendars/'.self::cal().'/events?conferenceDataVersion=1&sendUpdates='.(setting('gcal_invite','1')==='1'?'all':'none'),$ev);
      $link=$r['hangoutLink']??'';
      DB::update('appointments',['gcal_id'=>$r['id']??'','meet_link'=>$link]+($link?['location'=>$link]:[]),'id=?',[$apptId]);
      Settings::set('gcal_busy','');
      if($a['lead_id'])event($a['lead_id'],'note','Added to Google Calendar'.($link?' with Meet link':''),'cal');}
    catch(Throwable $e){logmsg('GCal push: '.$e->getMessage());if($a['lead_id'])event($a['lead_id'],'error','Google Calendar sync failed: '.$e->getMessage(),'cal');}}
  static function cancel($apptId){
    $g=DB::val('SELECT gcal_id FROM appointments WHERE id=?',[$apptId]);
    if(!$g||!self::ready())return;
    try{self::api('DELETE','calendars/'.self::cal().'/events/'.rawurlencode($g).'?sendUpdates=all');Settings::set('gcal_busy','');}catch(Throwable $e){logmsg('GCal cancel: '.$e->getMessage());}}}
