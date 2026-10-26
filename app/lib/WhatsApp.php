<?php
final class WhatsApp{
  static function provider(){return setting('wa_provider','manual');}
  static function link($phone,$text){return 'https://wa.me/'.phone_norm($phone).'?text='.rawurlencode($text);}
  // $files = [ ['url'=>..,'name'=>..], ... ]; returns message id or 'manual'
  static function send($phone,$text,$files,$vars){
    $to=phone_norm($phone);
    if(strlen($to)<8)throw new Exception('Invalid WhatsApp number');
    $p=self::provider();
    if($p==='cloud')return self::cloud($to,$text,$files,$vars);
    if($p==='generic')return self::generic($to,$text,$files);
    return 'manual';}
  private static function cloud($to,$text,$files,$vars){
    $id=setting('wa_phone_id');$tk=setting('wa_access_token');
    if(!$id||!$tk)throw new Exception('WhatsApp Cloud API not configured');
    $u='https://graph.facebook.com/'.setting('wa_api_version','v21.0').'/'.$id.'/messages';
    $h=['Authorization: Bearer '.$tk];
    $first=null;
    // Upload PDFs to WhatsApp so the file itself is delivered (no public link needed)
    foreach($files as &$f)$f['doc']=!empty($f['path'])&&is_file($f['path'])?['id'=>self::upload($f['path'],$tk,$id),'filename'=>$f['name']]:['link'=>$f['url'],'filename'=>$f['name']];
    unset($f);
    if($tpl=setting('wa_template')){
      $params=array_map(fn($x)=>['type'=>'text','text'=>tpl(trim($x),$vars)],array_filter(explode('|',setting('wa_template_params','{{business_name}}')),'strlen'));
      $comp=[];
      if($files)$comp[]=['type'=>'header','parameters'=>[['type'=>'document','document'=>$files[0]['doc']]]];
      if($params)$comp[]=['type'=>'body','parameters'=>array_values($params)];
      $r=http_json($u,['headers'=>$h,'json'=>['messaging_product'=>'whatsapp','to'=>$to,'type'=>'template','template'=>['name'=>$tpl,'language'=>['code'=>setting('wa_template_lang','en')],'components'=>$comp]]]);
      if($r['code']!==200)throw new Exception('WhatsApp: '.($r['data']['error']['message']??$r['body']));
      $first=$r['data']['messages'][0]['id']??'';
      array_shift($files);}
    else{
      // Text goes as the caption of the first PDF, so the customer gets one message with the file attached
      $doc=$files?array_shift($files)['doc']+['caption'=>mb_substr($text,0,1024)]:null;
      $r=http_json($u,['headers'=>$h,'json'=>$doc?['messaging_product'=>'whatsapp','to'=>$to,'type'=>'document','document'=>$doc]:['messaging_product'=>'whatsapp','to'=>$to,'type'=>'text','text'=>['body'=>$text,'preview_url'=>true]]]);
      if($r['code']!==200)throw new Exception('WhatsApp: '.($r['data']['error']['message']??$r['body']));
      $first=$r['data']['messages'][0]['id']??'';}
    foreach($files as $f)http_json($u,['headers'=>$h,'json'=>['messaging_product'=>'whatsapp','to'=>$to,'type'=>'document','document'=>$f['doc']]]);
    Usage::log('whatsapp',setting('wa_template')?'template':'message',1+count($files));
    return $first;}
  private static function upload($path,$tk,$phoneId){
    $ch=curl_init('https://graph.facebook.com/'.setting('wa_api_version','v21.0').'/'.$phoneId.'/media');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$tk],CURLOPT_POSTFIELDS=>['messaging_product'=>'whatsapp','type'=>'application/pdf','file'=>new CURLFile($path,'application/pdf',basename($path))]]);
    $b=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $d=json_decode((string)$b,true);
    if($code!==200||empty($d['id']))throw new Exception('WhatsApp media upload failed: '.($d['error']['message']??substr((string)$b,0,200)));
    return $d['id'];}
  private static function generic($to,$text,$files){
    $url=setting('wa_api_url');if(!$url)throw new Exception('WhatsApp API URL not configured');
    $hdr=[];foreach(jd(setting('wa_api_headers')) as $k=>$v)$hdr[]=$k.': '.$v;
    $tplb=setting('wa_api_body');
    $esc=fn($s)=>substr(json_encode((string)$s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),1,-1);
    $ids=[];
    $list=$files?:[['url'=>'','name'=>'']];
    foreach($list as $i=>$f){
      $vars=['phone'=>$to,'message'=>$esc($i===0?$text:''),'file_url'=>$esc($f['url']),'file_name'=>$esc($f['name']),'file_base64'=>!empty($f['path'])&&strpos($tplb,'{{file_base64}}')!==false&&is_file($f['path'])?base64_encode(file_get_contents($f['path'])):'','token'=>setting('wa_api_token')];
      $body=tpl($tplb,$vars);
      $u=tpl($url,['phone'=>$to,'token'=>urlencode(setting('wa_api_token'))]);
      $r=http($u,['method'=>setting('wa_api_method','POST'),'body'=>$body,'headers'=>$hdr,'timeout'=>40]);
      if($r['code']<200||$r['code']>=300)throw new Exception('WhatsApp API HTTP '.$r['code'].': '.substr($r['body'],0,200));
      $ids[]=substr($r['body'],0,60);}
    Usage::log('whatsapp_api','message',count($ids));
    return 'api:'.md5(implode('|',$ids));}}
