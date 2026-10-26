<?php
use PHPMailer\PHPMailer\PHPMailer;
final class Mailer{
  static function smtp(){return setting('mail_transport','php')==='smtp';}
  // PHP mail() works out of the box on shared hosting; SMTP is optional for better delivery
  static function ready(){return self::smtp()?setting('smtp_host')&&setting('smtp_user'):(function_exists('mail')&&self::from()!=='');}
  static function from(){return setting('from_email',self::smtp()?setting('smtp_user'):setting('company_email'));}
  static function send($to,$subject,$html,$text,$files=[]){
    $m=new PHPMailer(true);
    if(self::smtp()){
      $m->isSMTP();
      $m->Host=setting('smtp_host');$m->Port=(int)setting('smtp_port','465');
      $m->SMTPAuth=true;$m->Username=setting('smtp_user');$m->Password=setting('smtp_pass');
      $sec=setting('smtp_secure','ssl');$m->SMTPSecure=$sec==='none'?'':$sec;$m->SMTPAutoTLS=$sec!=='none';}
    else{$m->isMail();$m->Sender=self::from();}
    $m->CharSet='UTF-8';$m->Timeout=30;
    $m->setFrom(self::from(),setting('from_name',setting('company_name')));
    if($r=setting('reply_to'))$m->addReplyTo($r);
    if($b=setting('bcc_email'))$m->addBCC($b);
    $m->addAddress($to);
    $m->Subject=$subject;$m->isHTML(true);$m->Body=$html;$m->AltBody=$text;
    foreach($files as $name=>$path)if(is_file($path))$m->addAttachment($path,$name);
    try{$m->send();}
    catch(Throwable $e){
      if(self::smtp())throw $e;
      // PHP mail() often refuses a "-f" sender the host does not own, or is routed through sendmail – retry both ways
      $ok=false;
      try{$m->Sender='';$m->isMail();$m->send();$ok=true;}catch(Throwable $e2){}
      if(!$ok&&is_executable('/usr/sbin/sendmail')){try{$m->isSendmail();$m->send();$ok=true;}catch(Throwable $e3){}}
      if(!$ok)throw new Exception('Your hosting did not accept the email (PHP mail is disabled or the From address is not allowed). Set the From email to a mailbox on your own domain, or switch to SMTP in Settings → Messaging → Email. ('.$e->getMessage().')');}
    Usage::log('email',$m->Mailer);
    return $m->getLastMessageID();}
  static function html($text,$pixel=''){
    $acc=setting('brand_accent','#B8860B');
    $t=nl2br(e($text));
    $t=preg_replace('~(https?://[^\s<]+)~','<a href="$1" style="color:'.$acc.'">$1</a>',$t);
    $logo=base_url(setting('company_logo','assets/img/default-logo.png'));
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1a1a1a;max-width:600px">'.$t.'<br><br><table cellpadding="0" cellspacing="0" style="border-top:2px solid '.$acc.';padding-top:10px"><tr><td style="background:#0b0b0b;padding:8px;border-radius:6px"><img src="'.e($logo).'" height="48" alt=""></td><td style="padding-left:12px;font-size:12px;color:#555"><b style="color:#0b0b0b">'.e(setting('company_name')).'</b><br>'.e(setting('company_phone')).' · '.e(setting('company_website')).'</td></tr></table>'.($pixel?'<img src="'.e($pixel).'" width="1" height="1" alt="" style="display:block">':'').'</div>';}
  // Minimal IMAP reader (no php-imap extension needed). Returns list of sender emails since $days ago.
  static function inboxSenders($days=14){
    $host=setting('imap_host');if(!$host)return [];
    $port=(int)setting('imap_port','993');
    $fp=@stream_socket_client(($port===993?'ssl://':'tcp://').$host.':'.$port,$en,$es,20);
    if(!$fp)throw new Exception('IMAP connect failed: '.$es);
    stream_set_timeout($fp,30);
    $n=0;
    $cmd=function($c)use($fp,&$n){$tag='A'.(++$n);fwrite($fp,$tag.' '.$c."\r\n");$out='';while(($l=fgets($fp))!==false){$out.=$l;if(strpos($l,$tag.' ')===0)break;}return $out;};
    fgets($fp);
    $q=fn($s)=>'"'.addcslashes($s,'"\\').'"';
    $r=$cmd('LOGIN '.$q(setting('imap_user',setting('smtp_user'))).' '.$q(setting('imap_pass',setting('smtp_pass'))));
    if(!preg_match('/^A\d+ OK/m',$r)){fclose($fp);throw new Exception('IMAP login failed');}
    $cmd('SELECT INBOX');
    $r=$cmd('UID SEARCH SINCE '.date('d-M-Y',strtotime("-$days days")));
    $uids=preg_match('/\* SEARCH ([\d ]+)/',$r,$m)?array_slice(explode(' ',trim($m[1])),-300):[];
    $out=[];
    if($uids){
      $r=$cmd('UID FETCH '.implode(',',$uids).' (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])');
      if(preg_match_all('/^From:.*?([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})/mi',$r,$mm))foreach($mm[1] as $e)$out[strtolower($e)]=1;}
    $cmd('LOGOUT');fclose($fp);
    return array_keys($out);}}
