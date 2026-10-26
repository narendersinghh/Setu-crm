<?php
// CRM leads: manual entry, captured leads (forms/ads/webhooks), statuses, custom fields
final class CRM{
  const CORE=['name','contact_name','email','phone','alt_phone','website','address','city','category','service','deal_value','message','tags','follow_up_at'];
  static function fields(){static $f=null;return $f??=DB::all('SELECT * FROM fields ORDER BY sort,id');}
  const ALIAS=['name'=>['company','company_name','business','business_name','organisation','organization','firm','shop_name'],'contact_name'=>['name','full_name','fullname','your_name','first_name','contact','contact_name','customer_name','person'],'email'=>['email','email_address','e_mail','your_email','mail','work_email'],'phone'=>['phone','phone_number','mobile','mobile_number','contact_number','whatsapp','whatsapp_number','tel','telephone','your_phone','number','work_phone'],'alt_phone'=>['alt_phone','alternate_phone','phone_2','landline'],'city'=>['city','location','town'],'address'=>['address','street_address','full_address'],'website'=>['website','url','site','web','website_url'],'service'=>['service','services','interested_in','product','requirement_type','enquiry_for','subject','select_service'],'message'=>['message','your_message','comments','comment','query','enquiry','details','description','notes','requirement'],'deal_value'=>['budget','deal_value','amount','value']];
  // Form-tool metadata that should not become lead fields
  const META=['page_url','user_agent','remote_ip','powered_by','referrer','referer','form_id','form_name','queried_id','g_recaptcha_response','_csrf','k','redirect','_hp','submit','action','nonce','wpnonce','hp','csrf','wp_http_referer'];
  static function key($k){return trim(strtolower(preg_replace('/[^a-z0-9]+/i','_',trim((string)$k))),'_');}
  // Which Setu field an incoming key fills automatically ('' = saved in notes)
  static function auto($nk){foreach(self::ALIAS as $f=>$alts)if(in_array($nk,$alts,true))return $f;foreach(self::fields() as $f)if($f['fkey']===$nk)return 'cf:'.$f['fkey'];if($nk==='last_name')return 'contact_name';
    // Loose match for labels like "Your mobile number" or "Which service?"
    foreach(['email'=>'email','mail'=>'email','phone'=>'phone','mobile'=>'phone','whatsapp'=>'phone','contact_no'=>'phone','company'=>'name','business'=>'name','budget'=>'deal_value','service'=>'service','city'=>'city','address'=>'address','website'=>'website','message'=>'message','requirement'=>'message','enquiry'=>'message','name'=>'contact_name'] as $w=>$f)if(strpos($nk,$w)!==false)return $f;
    return '';}
  // Flatten payloads from Elementor (simple + "Advanced data"), nested JSON etc. Returns [fields, labels, meta]
  static function flatten($in){
    $lab=[];$meta=[];
    if(isset($in['fields'])&&is_array($in['fields'])){
      $f=[];foreach($in['fields'] as $k=>$x)if(is_array($x)&&array_key_exists('value',$x)){$id=self::key($x['id']??$k);$f[$id]=$x['value'];$lab[$id]=$x['title']??$id;}
      foreach((array)($in['meta']??[]) as $k=>$x)$meta[self::key($k)]=is_array($x)?($x['value']??''):$x;
      if(isset($in['form']['name']))$meta['form_name']=$in['form']['name'];
      $in=$f+array_diff_key($in,['fields'=>1,'meta'=>1,'form'=>1]);}
    $out=[];
    foreach($in as $k=>$v){
      $nk=self::key($k);if($nk==='')continue;
      if(is_array($v)&&$v&&array_keys($v)!==range(0,count($v)-1)){$px=in_array($nk,['form_fields','fields','data','lead','contact','input','form'],true)?'':$nk.'_';foreach($v as $k2=>$v2)if(is_scalar($v2)){$out[$px.self::key($k2)]=$v2;$lab[$px.self::key($k2)]=$px?$k.' › '.$k2:(string)$k2;}continue;}
      if(is_array($v))$v=implode(', ',array_map('strval',array_filter($v,'is_scalar')));
      $out[$nk]=$v;$lab[$nk]??=(string)$k;}
    $pw=isset($out['powered_by']);
    foreach($out as $k=>$v)if(in_array($k,self::META,true)||($pw&&in_array($k,['date','time'],true))){$meta[$k]=$v;unset($out[$k]);}
    return [$out,$lab,$meta];}
  // Map incoming keys to Setu fields. $map = [incoming_key => setu_field | 'cf:key' | 'message' | 'ignore'] overrides automatic detection
  static function normalize($in,$map=[]){
    [$in,$lab]=self::flatten($in);
    $out=[];$rest=[];$custom=[];
    foreach($in as $nk=>$v){
      $v=trim((string)$v);if($v==='')continue;
      $t=$map[$nk]??'';if($t==='')$t=self::auto($nk);if($t===''&&isset($lab[$nk]))$t=self::auto(self::key($lab[$nk]));
      if($t==='ignore')continue;
      if(strncmp($t,'cf:',3)===0){$custom[substr($t,3)]=$v;continue;}
      if($t==='message'&&isset($out['message'])){$rest[$nk]=$v;continue;}
      if($t!==''&&in_array($t,self::CORE,true)||$t==='message'){if(isset($out[$t])&&in_array($t,['contact_name','name','address'],true))$out[$t].=' '.$v;elseif(!isset($out[$t]))$out[$t]=$v;else $rest[$nk]=$v;continue;}
      $rest[$nk]=$v;}
    return [$out,$custom,$rest];}
  // Create (or merge into a recent duplicate) a CRM lead. Returns lead id.
  static function create($d,$source='manual',$detail='',$custom=[],$extra=[]){
    $d=array_intersect_key($d,array_flip(self::CORE));
    $d['name']=trim($d['name']??'')?:trim($d['contact_name']??'')?:'Unnamed lead';
    if(isset($d['deal_value']))$d['deal_value']=(float)preg_replace('/[^\d.]/','',$d['deal_value'])?:null;
    if(isset($d['follow_up_at'])&&!$d['follow_up_at'])unset($d['follow_up_at']);
    $email=strtolower(trim($d['email']??''));$ph=$d['phone']??'';
    $dup=null;
    if($email||$ph){
      $tail=$ph?substr(preg_replace('/\D/','',$ph),-10):'';
      $w=[];$args=[];if($email){$w[]='LOWER(email)=?';$args[]=$email;}if($tail!==''){$w[]="RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),10)=?";$args[]=$tail;}
      $dup=$w?DB::row('SELECT id,custom,message FROM leads WHERE is_lead=1 AND created_at>NOW()-INTERVAL 30 DAY AND ('.implode(' OR ',$w).') ORDER BY id DESC LIMIT 1',$args):null;}
    if($extra)$custom['_extra']=$extra;
    if($dup){
      $u=array_filter($d,fn($v)=>$v!==''&&$v!==null);unset($u['name']);
      $c=jd($dup['custom']);$c=$custom+$c;
      if(!empty($d['message']))$u['message']=trim(($dup['message']?$dup['message']."\n---\n":'').$d['message']);
      DB::update('leads',$u+['custom'=>je($c)],'id=?',[$dup['id']]);
      event($dup['id'],'enriched','New enquiry received again via '.(lead_sources()[$source][0]??$source).($detail?' ('.$detail.')':''),$source);
      return $dup['id'];}
    $id=DB::insert('leads',$d+['campaign_id'=>0,'place_id'=>'crm-'.token(8),'is_lead'=>1,'source'=>$source,'source_detail'=>mb_substr($detail,0,180),'status'=>'new','stage'=>'new','custom'=>$custom?je($custom):null,'token'=>token(16),'lead_at'=>date('Y-m-d H:i:s'),'last_activity'=>date('Y-m-d H:i:s'),'phone_source'=>$ph?'form':'']);
    event($id,'found','New lead from '.(lead_sources()[$source][0]??$source).($detail?' · '.$detail:''),$source);
    Automation::fire('lead_created',$id);
    return $id;}
  static function setStatus($id,$st,$note=''){
    $l=DB::row('SELECT status,stage FROM leads WHERE id=?',[$id]);
    if(!$l||$l['status']===$st)return;
    $u=['status'=>$st,'is_lead'=>1];
    if($st==='won')$u['stage']='won';
    if($st==='lost'&&$l['stage']!=='won')$u['stage']='lost';
    DB::update('leads',$u,'id=?',[$id]);
    event($id,'stage','Status: '.(crm_statuses()[$l['status']][0]??$l['status']).' → '.(crm_statuses()[$st][0]??$st).($note?' · '.$note:''));
    Automation::fire('status_changed',$id,['status'=>$st]);}
  static function addTag($id,$tag){
    $tag=trim(str_replace(',','',$tag));if($tag==='')return;
    $t=array_filter(array_map('trim',explode(',',(string)DB::val('SELECT tags FROM leads WHERE id=?',[$id]))));
    if(in_array($tag,$t,true))return;
    $t[]=$tag;DB::update('leads',['tags'=>mb_substr(implode(', ',$t),0,490)],'id=?',[$id]);
    event($id,'note','Tag added: '.$tag);
    Automation::fire('tag_added',$id,['tag'=>$tag]);}
  // Form control for a custom field
  static function input($f,$v=''){
    $n='cf['.e($f['fkey']).']';$req=$f['required']?' required':'';
    switch($f['type']){
      case 'textarea':return '<textarea name="'.$n.'" rows="2"'.$req.'>'.e($v).'</textarea>';
      case 'select':$o='<select name="'.$n.'"'.$req.'><option value="">—</option>';foreach(array_filter(array_map('trim',explode("\n",(string)$f['options']))) as $x)$o.='<option '.($x===$v?'selected':'').'>'.e($x).'</option>';return $o.'</select>';
      case 'checkbox':return '<span class="chk"><input type="hidden" name="'.$n.'" value=""><input type="checkbox" name="'.$n.'" value="Yes" '.($v?'checked':'').'> Yes</span>';
      default:$t=['number'=>'number','date'=>'date','email'=>'email','phone'=>'tel','url'=>'url'][$f['type']]??'text';return '<input type="'.$t.'" name="'.$n.'" value="'.e($v).'"'.$req.'>';}}
  static function saveCustom($id,$in){
    $c=jd(DB::val('SELECT custom FROM leads WHERE id=?',[$id]));
    foreach(self::fields() as $f)if(array_key_exists($f['fkey'],$in))$c[$f['fkey']]=mb_substr(trim((string)$in[$f['fkey']]),0,2000);
    DB::update('leads',['custom'=>je($c)],'id=?',[$id]);}
  // CSV import with header row; returns count
  static function import($file){
    $fh=fopen($file,'r');if(!$fh)throw new Exception('Cannot read file');
    $head=fgetcsv($fh);if(!$head)throw new Exception('Empty CSV');
    $head[0]=preg_replace('/^\xEF\xBB\xBF/','',$head[0]);
    $n=0;
    while(($row=fgetcsv($fh))!==false){
      if(count(array_filter($row,'strlen'))===0)continue;
      $in=[];foreach($head as $i=>$h)$in[$h]=$row[$i]??'';
      [$d,$c,$rest]=self::normalize($in);
      foreach($in as $h=>$v){$k=strtolower(preg_replace('/[^a-z0-9]+/i','_',$h));if(in_array($k,self::CORE,true)&&!isset($d[$k])&&$v!=='')$d[$k]=$v;}
      if($rest)$d['message']=trim(($d['message']??'')."\n".implode("\n",array_map(fn($k,$v)=>"$k: $v",array_keys($rest),$rest)));
      self::create($d,'import','CSV '.date('d M Y'),$c);$n++;}
    fclose($fh);return $n;}}
