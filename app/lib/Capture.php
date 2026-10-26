<?php
// Lead capture from website forms, Meta lead ads, Google Ads lead forms and generic webhooks
final class Capture{
  static function types(){return ['website'=>['Website form','Paste a ready-made form or connect your existing form (WordPress, Elementor, Contact Form 7, WPForms, custom HTML) to this URL.','globe','blue'],'facebook'=>['Meta / Facebook Lead Ads','Instant-form leads from Facebook & Instagram ads arrive in seconds via the Meta webhook.','target','purple'],'google'=>['Google Ads lead forms','Lead form assets in Google Ads send each lead to this webhook.','zap','green'],'webhook'=>['Webhook / Zapier / Make / n8n','Send JSON or form data from any tool (Zapier, Make, n8n, Pabbly, IndiaMART, JustDial exports…).','plug','teal']];}
  static function url($s){return base_url('hook.php?k='.$s['token']);}
  static function handle($src){
    $cfg=jd($src['config']);
    $raw=file_get_contents('php://input');
    $json=json_decode($raw,true);
    $in=is_array($json)?$json:$_POST;
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
      if($src['type']==='facebook'&&isset($_GET['hub_mode'])){
        if(($_GET['hub_verify_token']??'')===($cfg['verify_token']??'')){echo $_GET['hub_challenge']??'';exit;}
        http_response_code(403);exit('Invalid verify token');}
      if($_GET)$in=array_diff_key($_GET,['k'=>1]);else{echo 'Setu lead capture endpoint is active.';exit;}}
    DB::update('sources',['last_payload'=>mb_substr($raw?:je($in),0,20000),'last_at'=>date('Y-m-d H:i:s')],'id=?',[$src['id']]);
    $map=(array)($cfg['map']??[]);
    $ids=[];
    switch($src['type']){
      case 'facebook':$ids=self::facebook($src,$cfg,$in,$raw);break;
      case 'google':$ids=self::google($src,$cfg,$in);break;
      default:
        if(!empty($in['_hp']))break; // honeypot spam
        unset($in['_hp']);
        self::learn($src,$cfg,$in);
        [$d,$c,$rest]=CRM::normalize($in,$map);
        if(!$d&&!$c)break;
        if($rest)$d['message']=trim(($d['message']??'')."\n".implode("\n",array_map(fn($k,$v)=>ucfirst(str_replace('_',' ',$k)).": $v",array_keys($rest),$rest)));
        [,,$meta]=CRM::flatten($in);
        $page=$meta['page_url']??($_SERVER['HTTP_REFERER']??'');
        $ids[]=CRM::create($d,$src['type'],$src['name'].($page?' · '.parse_url($page,PHP_URL_HOST).parse_url($page,PHP_URL_PATH):''),$c);}
    if($ids)DB::q('UPDATE sources SET received=received+? WHERE id=?',[count($ids),$src['id']]);
    return $ids;}
  private static function facebook($src,$cfg,$in,$raw){
    if(!empty($cfg['app_secret'])){$sig=$_SERVER['HTTP_X_HUB_SIGNATURE_256']??'';if(!hash_equals('sha256='.hash_hmac('sha256',$raw,$cfg['app_secret']),$sig)){http_response_code(401);exit;}}
    $ids=[];
    foreach($in['entry']??[] as $en)foreach($en['changes']??[] as $ch){
      $v=$ch['value']??[];$lg=$v['leadgen_id']??null;if(!$lg)continue;
      $tk=$cfg['page_token']??'';
      if(!$tk){logmsg('Meta lead '.$lg.' received but no Page access token saved');continue;}
      $r=http_json('https://graph.facebook.com/v21.0/'.rawurlencode($lg).'?'.http_build_query(['access_token'=>$tk,'fields'=>'field_data,created_time,ad_name,campaign_name,form_id,platform']),['timeout'=>20]);
      if($r['code']!==200){logmsg('Meta lead fetch failed: '.substr($r['body'],0,300));continue;}
      $fd=[];foreach($r['data']['field_data']??[] as $f)$fd[$f['name']]=implode(', ',$f['values']??[]);
      DB::update('sources',['last_payload'=>je($fd)],'id=?',[$src['id']]);self::learn($src,$cfg,$fd);
      [$d,$c,$rest]=CRM::normalize($fd,(array)($cfg['map']??[]));
      if($rest)$d['message']=trim(($d['message']??'')."\n".implode("\n",array_map(fn($k,$v)=>ucfirst(str_replace('_',' ',$k)).": $v",array_keys($rest),$rest)));
      $ids[]=CRM::create($d,'facebook',trim(($r['data']['campaign_name']??'').' · '.($r['data']['ad_name']??''),' ·'),$c,['leadgen_id'=>$lg,'form_id'=>$v['form_id']??'','platform'=>$r['data']['platform']??'']);}
    // Also accept already-flattened payloads (Zapier/Make "Facebook Lead Ads" apps)
    if(!$ids&&empty($in['entry'])&&$in){self::learn($src,$cfg,$in);[$d,$c]=CRM::normalize($in,(array)($cfg['map']??[]));if($d)$ids[]=CRM::create($d,'facebook',$src['name'],$c);}
    return $ids;}
  private static function google($src,$cfg,$in){
    if(!empty($cfg['google_key'])&&($in['google_key']??'')!==$cfg['google_key']){http_response_code(401);exit('Invalid key');}
    $m=['FULL_NAME'=>'contact_name','FIRST_NAME'=>'contact_name','EMAIL'=>'email','WORK_EMAIL'=>'email','PHONE_NUMBER'=>'phone','WORK_PHONE'=>'phone','COMPANY_NAME'=>'name','CITY'=>'city','STREET_ADDRESS'=>'address'];
    $d=[];$rest=[];
    foreach($in['user_column_data']??[] as $c){
      $id=$c['column_id']??'';$val=$c['string_value']??'';
      if($id==='LAST_NAME'){$d['contact_name']=trim(($d['contact_name']??'').' '.$val);continue;}
      if(isset($m[$id])&&empty($d[$m[$id]]))$d[$m[$id]]=$val;else $rest[$c['column_name']??$id]=$val;}
    if(!$d)return [];
    if($rest)$d['message']=implode("\n",array_map(fn($k,$v)=>"$k: $v",array_keys($rest),$rest));
    $test=!empty($in['is_test']);
    return [CRM::create($d,'google',($test?'TEST · ':'').'Campaign '.($in['campaign_id']??'').($in['gcl_id']??''?' · gclid':''),[],['lead_id'=>$in['lead_id']??'','form_id'=>$in['form_id']??'','gcl_id'=>$in['gcl_id']??''])];}
  // Remember the field names a source sends (with a sample value) so they can be mapped in Settings
  private static function learn($src,&$cfg,$in){
    [$f,$lab]=CRM::flatten($in);$k=(array)($cfg['keys']??[]);$ch=false;
    foreach($f as $nk=>$v){$s=mb_substr(trim((string)$v),0,60);if(!isset($k[$nk])||$k[$nk][1]!==$s){$k[$nk]=[mb_substr((string)($lab[$nk]??$nk),0,60),$s];$ch=true;}}
    if($ch){$cfg['keys']=array_slice($k,-40,null,true);DB::update('sources',['config'=>je($cfg)],'id=?',[$src['id']]);}}
  // Ready-to-paste HTML form for a website source
  static function snippet($s){
    $u=self::url($s);$acc=setting('brand_accent','#B8860B');
    return '<form action="'.$u.'" method="post" style="max-width:420px;font-family:Arial,sans-serif;display:grid;gap:10px">'."\n".'  <input name="name" placeholder="Your name" required style="padding:10px;border:1px solid #ccc;border-radius:6px">'."\n".'  <input name="phone" placeholder="Phone / WhatsApp" required style="padding:10px;border:1px solid #ccc;border-radius:6px">'."\n".'  <input name="email" type="email" placeholder="Email" style="padding:10px;border:1px solid #ccc;border-radius:6px">'."\n".'  <input name="company" placeholder="Business name" style="padding:10px;border:1px solid #ccc;border-radius:6px">'."\n".'  <textarea name="message" placeholder="How can we help?" style="padding:10px;border:1px solid #ccc;border-radius:6px"></textarea>'."\n".'  <input name="_hp" style="display:none" tabindex="-1" autocomplete="off">'."\n".'  <input type="hidden" name="redirect" value="">'."\n".'  <button style="padding:12px;background:'.$acc.';color:#fff;border:0;border-radius:6px;font-weight:bold">Send enquiry</button>'."\n".'</form>';}
  static function jsSnippet($s){
    return "<script>\n/* Setu: send any existing form on this page to your CRM (keeps the form's normal behaviour) */\ndocument.addEventListener('submit',function(e){try{var f=e.target,d=new FormData(f);navigator.sendBeacon('".self::url($s)."',d);}catch(x){}},true);\n</script>";}}
