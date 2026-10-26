<?php
// AI voice calls: Sarvam AI, Bland AI, Vapi, Retell AI or any HTTP API
final class Voice{
  static function providers(){return [
    'sarvam'=>['Sarvam AI','Indian-language voice agents (Hindi, Marathi, Tamil, Telugu, English…). Build the agent in Sarvam, then paste its IDs here.',['sarvam_api_key'=>'Workspace API key','sarvam_org'=>'Org ID','sarvam_workspace'=>'Workspace ID','sarvam_app'=>'Agent (app) ID','sarvam_version'=>'Agent version (number)','sarvam_connection'=>'Connection ID','sarvam_number'=>'Agent phone number']],
    'bland'=>['Bland AI','Give the AI a task in plain words – no agent setup needed.',['bland_api_key'=>'API key','bland_from'=>'From number (optional)','bland_voice'=>'Voice (e.g. maya, josh – optional)']],
    'vapi'=>['Vapi','Uses an assistant you built in Vapi.',['vapi_api_key'=>'Private API key','vapi_assistant'=>'Assistant ID','vapi_phone'=>'Phone number ID']],
    'retell'=>['Retell AI','Uses a Retell agent and your Retell phone number.',['retell_api_key'=>'API key','retell_agent'=>'Agent ID','retell_from'=>'From number (+91…)']],
    'custom'=>['Other provider (HTTP)','Any voice API: we POST JSON to your URL. Placeholders: {{phone}} {{task}} {{first_message}} {{webhook}} {{business_name}} {{contact_name}}',['voice_url'=>'Endpoint URL','voice_headers'=>'Headers (JSON)','voice_body'=>'Body template (JSON)']]];}
  static function ready(){$p=setting('voice_provider');return $p&&isset(self::providers()[$p]);}
  static function start($lid,$o=[]){
    $l=DB::row('SELECT * FROM leads WHERE id=?',[$lid]);
    if(!$l||!$l['phone'])throw new Exception('No phone number');
    $p=setting('voice_provider');
    if(!self::ready())throw new Exception('No AI voice provider connected (Settings → Voice calls)');
    $tk=token(16);
    $cid=DB::insert('calls',['lead_id'=>$lid,'provider'=>$p,'token'=>$tk,'status'=>'queued']);
    $vars=lead_vars($l);
    $task=tpl(($o['task']??'')?:setting('voice_task'),$vars);$first=tpl(($o['first']??'')?:setting('voice_first_message'),$vars);
    $to='+'.phone_norm($l['phone']);$hook=base_url('callhook.php?t='.$tk);
    try{$ext=self::dial($p,$to,$task,$first,$hook,$vars,$l);}
    catch(Throwable $e){DB::update('calls',['status'=>'failed','summary'=>$e->getMessage()],'id=?',[$cid]);event($lid,'error','AI call failed: '.$e->getMessage(),'call');throw $e;}
    DB::update('calls',['ext_id'=>mb_substr((string)$ext,0,190),'status'=>'calling'],'id=?',[$cid]);
    Usage::log('voice_'.$p,'call',1);
    event($lid,'call','AI voice call started to '.$to.' via '.self::providers()[$p][0],'call');
    return $cid;}
  static function call($lid,$p){self::start($lid,$p);}
  private static function dial($p,$to,$task,$first,$hook,$vars,$l){
    $S=fn($k)=>setting($k);
    switch($p){
      case 'sarvam':
        $body=['app_config'=>['app_id'=>$S('sarvam_app'),'app_version'=>(int)$S('sarvam_version')?:1,'connection_config'=>['connection_id'=>$S('sarvam_connection'),'agent_phone_number'=>$S('sarvam_number')],'agent_variables'=>['business_name'=>$vars['business_name'],'contact_name'=>$vars['contact_name'],'task'=>$task,'agency_name'=>$vars['agency_name']]],'user_config'=>['user_phone_number'=>$to],'webhook_config'=>['url'=>$hook,'metadata'=>['lead_id'=>$l['id']]]];
        if($first)$body['app_config']['app_overrides']=['initial_bot_message'=>$first]+(setting('voice_language')?['initial_language_name'=>setting('voice_language')]:[]);
        $r=http_json('https://apps.sarvam.ai/api/outbounds/v1/orgs/'.rawurlencode($S('sarvam_org')).'/workspaces/'.rawurlencode($S('sarvam_workspace')).'/outbounds',['json'=>$body,'headers'=>['X-API-Key: '.$S('sarvam_api_key')],'timeout'=>30]);
        if($r['code']!==200)throw new Exception('Sarvam HTTP '.$r['code'].': '.substr($r['body'],0,300));
        return $r['data']['attempt_id']??'';
      case 'bland':
        $body=['phone_number'=>$to,'task'=>$task,'first_sentence'=>$first,'webhook'=>$hook,'record'=>true,'metadata'=>['lead_id'=>$l['id']]];
        if($S('bland_from'))$body['from']=$S('bland_from');
        if($S('bland_voice'))$body['voice']=$S('bland_voice');
        $r=http_json('https://api.bland.ai/v1/calls',['json'=>$body,'headers'=>['authorization: '.$S('bland_api_key')],'timeout'=>30]);
        if($r['code']!==200||($r['data']['status']??'')==='error')throw new Exception('Bland HTTP '.$r['code'].': '.substr($r['body'],0,300));
        return $r['data']['call_id']??'';
      case 'vapi':
        $body=['assistantId'=>$S('vapi_assistant'),'phoneNumberId'=>$S('vapi_phone'),'customer'=>['number'=>$to,'name'=>mb_substr($vars['contact_name'],0,40)],'assistantOverrides'=>['firstMessage'=>$first,'variableValues'=>['business_name'=>$vars['business_name'],'task'=>$task],'server'=>['url'=>$hook]]];
        $r=http_json('https://api.vapi.ai/call',['json'=>$body,'headers'=>['Authorization: Bearer '.$S('vapi_api_key')],'timeout'=>30]);
        if($r['code']<200||$r['code']>=300)throw new Exception('Vapi HTTP '.$r['code'].': '.substr($r['body'],0,300));
        return $r['data']['id']??'';
      case 'retell':
        $body=['from_number'=>$S('retell_from'),'to_number'=>$to,'override_agent_id'=>$S('retell_agent'),'retell_llm_dynamic_variables'=>['business_name'=>$vars['business_name'],'contact_name'=>$vars['contact_name'],'task'=>$task],'metadata'=>['lead_id'=>$l['id'],'hook'=>$hook]];
        $r=http_json('https://api.retellai.com/v2/create-phone-call',['json'=>$body,'headers'=>['Authorization: Bearer '.$S('retell_api_key')],'timeout'=>30]);
        if($r['code']<200||$r['code']>=300)throw new Exception('Retell HTTP '.$r['code'].': '.substr($r['body'],0,300));
        return $r['data']['call_id']??'';
      case 'custom':
        $esc=fn($s)=>substr(json_encode((string)$s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),1,-1);
        $body=tpl($S('voice_body'),array_map($esc,['phone'=>$to,'task'=>$task,'first_message'=>$first,'webhook'=>$hook]+$vars));
        $h=[];foreach(jd($S('voice_headers')) as $k=>$v)$h[]=$k.': '.$v;
        $r=http($S('voice_url'),['body'=>$body,'headers'=>array_merge(['Content-Type: application/json'],$h),'timeout'=>30]);
        if($r['code']<200||$r['code']>=300)throw new Exception('Voice API HTTP '.$r['code'].': '.substr($r['body'],0,300));
        $d=json_decode($r['body'],true);return is_array($d)?($d['id']??$d['call_id']??$d['attempt_id']??''):'';}
    throw new Exception('Unknown voice provider');}
  // Find the first matching key anywhere in a nested payload
  private static function find($a,$keys){
    if(!is_array($a))return null;
    foreach($keys as $k)if(isset($a[$k])&&$a[$k]!==''&&!is_array($a[$k]))return $a[$k];
    foreach($a as $v)if(is_array($v)&&($x=self::find($v,$keys))!==null)return $x;
    return null;}
  // callhook.php: provider posts the result when the call ends
  static function webhook($call,$d){
    $status=strtolower((string)(self::find($d,['call_status','status','disconnection_reason','endedReason','end_reason','outcome'])??'completed'));
    $type=strtolower((string)(self::find($d,['type','event'])??''));
    if(in_array($type,['status-update','speech-update','transcript','conversation-update','call_started','call.started'],true)&&!self::find($d,['transcript','concatenated_transcript','summary']))return;
    $dur=self::find($d,['duration','durationSeconds','duration_seconds','call_length','call_duration']);
    if($dur!==null&&($d['call_length']??null)!==null)$dur=round((float)$d['call_length']*60);
    $tr=self::find($d,['concatenated_transcript','transcript','transcript_text']);
    $sum=self::find($d,['summary','call_summary','analysis_summary']);
    $rec=self::find($d,['recording_url','recordingUrl','recording','stereoRecordingUrl']);
    $u=['status'=>mb_substr($status,0,20),'duration'=>$dur!==null?(int)$dur:null,'transcript'=>$tr?mb_substr((string)$tr,0,60000):null,'summary'=>$sum?mb_substr((string)$sum,0,4000):null,'recording'=>$rec?mb_substr((string)$rec,0,490):''];
    DB::update('calls',array_filter($u,fn($v)=>$v!==null&&$v!==''),'id=?',[$call['id']]);
    if($dur&&!$call['duration'])Usage::log('voice_'.$call['provider'],'minutes',0,(int)$dur);
    event($call['lead_id'],'call','AI call '.$status.($dur?' · '.gmdate('i:s',(int)$dur):'').($sum?': '.mb_strimwidth((string)$sum,0,400,'…'):''),'call');
    if($tr&&preg_match('/\b(yes|sure|ok|okay|haan|ha|ji)\b.*\b(meeting|call|appointment|tomorrow|monday|tuesday|wednesday|thursday|friday|saturday)\b/i',(string)$tr.' '.$sum))CRM::addTag($call['lead_id'],'call-interested');
    Automation::fire('call_completed',$call['lead_id']);}}
