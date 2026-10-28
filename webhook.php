<?php
// WhatsApp Cloud API webhook: delivery/read receipts and incoming replies
require __DIR__.'/app/bootstrap.php';
session_write_close();
if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
  $vt=setting('wa_verify_token');
  if($vt&&hash_equals($vt,(string)($_GET['hub_verify_token']??''))){header('Content-Type: text/plain');echo preg_replace('/[^\w.-]/','',(string)($_GET['hub_challenge']??''));exit;}
  http_response_code(403);exit('Invalid verify token');}
$raw=file_get_contents('php://input');
if($sec=setting('wa_app_secret')){$sig=$_SERVER['HTTP_X_HUB_SIGNATURE_256']??'';if(!hash_equals('sha256='.hash_hmac('sha256',$raw,$sec),$sig)){http_response_code(401);exit;}}
$d=json_decode($raw,true);
foreach($d['entry']??[] as $en)foreach($en['changes']??[] as $ch){
  $v=$ch['value']??[];
  foreach($v['statuses']??[] as $s){
    $m=DB::row("SELECT id,lead_id FROM messages WHERE ext_id=? AND channel='whatsapp'",[$s['id']??'']);
    if(!$m)continue;
    $st=$s['status']??'';
    if(!in_array($st,['delivered','read','failed'],true))continue;
    DB::update('messages',['status'=>$st],'id=?',[$m['id']]);
    $rank=['sent'=>1,'delivered'=>2,'read'=>3];
    $cur=DB::val('SELECT wa_status FROM leads WHERE id=?',[$m['lead_id']]);
    if($st==='failed'||($rank[$st]??0)>($rank[$cur]??0))DB::update('leads',['wa_status'=>$st],'id=?',[$m['lead_id']]);
    if($st==='read')DB::q("UPDATE leads SET stage='opened' WHERE id=? AND stage='contacted'",[$m['lead_id']]);
    event($m['lead_id'],$st,'WhatsApp message '.$st.($st==='failed'?': '.($s['errors'][0]['title']??''):''),'whatsapp');}
  foreach($v['messages']??[] as $msg){
    $from=preg_replace('/\D/','',$msg['from']??'');
    if(strlen($from)<8)continue;
    $text=$msg['text']['body']??($msg['button']['text']??('['.($msg['type']??'message').']'));
    $tail=substr($from,-10);
    foreach(DB::all("SELECT id,phone FROM leads WHERE wa_status<>'' AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'.','') LIKE ? ORDER BY id DESC LIMIT 20",['%'.$tail]) as $l){
      if(substr(phone_norm($l['phone']),-10)!==$tail)continue;
      DB::insert('messages',['lead_id'=>$l['id'],'channel'=>'whatsapp','direction'=>'in','status'=>'received','ext_id'=>mb_substr($msg['id']??'',0,190),'body'=>$text]);
      Outreach::markReplied($l['id'],'whatsapp',$text);}}}
echo 'OK';
Cron::kick();
