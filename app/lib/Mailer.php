<?php
use PHPMailer\PHPMailer\PHPMailer;
final class Mailer{
  // Email services used with just an API key (sent over their HTTPS API – no SMTP settings needed)
  // [name, what to paste, where to get it, extra field: ''|secret|domain, regions]
  static function apis(){return [
    'brevo'=>['Brevo (Sendinblue)','API key (starts with xkeysib-)','https://app.brevo.com/settings/keys/api','',[]],
    'sendgrid'=>['SendGrid','API key (starts with SG.)','https://app.sendgrid.com/settings/api_keys','',[]],
    'mailjet'=>['Mailjet','API key','https://app.mailjet.com/account/apikeys','secret',[]],
    'postmark'=>['Postmark','Server API token','https://account.postmarkapp.com/servers','',[]],
    'resend'=>['Resend','API key (starts with re_)','https://resend.com/api-keys','',[]],
    'zeptomail'=>['Zoho ZeptoMail','Send Mail token','https://www.zoho.com/zeptomail/','',[]],
    'mailgun'=>['Mailgun','API key','https://app.mailgun.com/settings/api_security','domain',[]],
    'smtp2go'=>['SMTP2GO','API key (starts with api-)','https://app.smtp2go.com/settings/api_keys/','',[]],
    'elastic'=>['Elastic Email','API key','https://app.elasticemail.com/marketing/settings/new/manage-api','',[]]];}
  // Mailboxes over SMTP: only the email address and its (app) password are asked; [name, host, port, encryption, password hint]
  static function providers(){return [
    'custom'=>['Other / custom SMTP server','','','','Password'],
    'gmail'=>['Gmail / Google Workspace','smtp.gmail.com','587','tls','App password (Google Account → Security → App passwords)'],
    'zoho'=>['Zoho Mail','smtp.zoho.in','465','ssl','Mailbox or app-specific password'],
    'outlook'=>['Microsoft 365 / Outlook','smtp.office365.com','587','tls','Password or app password'],
    'hostinger'=>['Hostinger email','smtp.hostinger.com','465','ssl','Mailbox password'],
    'cpanel'=>['cPanel / my hosting mailbox','','465','ssl','Mailbox password'],
    'ses'=>['Amazon SES (SMTP)','email-smtp.ap-south-1.amazonaws.com','587','tls','SMTP password from SES → SMTP settings']];}
  // Fill server settings for known mailboxes (cPanel: mail.<your domain>)
  static function preset($k){
    $p=self::providers()[$k]??null;if(!$p||$k==='custom')return;
    if(in_array($k,['ses','cpanel'],true)&&setting('smtp_host')!=='')return;
    $host=$k==='cpanel'?'mail.'.substr(strrchr(setting('from_email'),'@')?:'@','1'):$p[1];
    Settings::many(['smtp_host'=>$host,'smtp_port'=>$p[2],'smtp_secure'=>$p[3]]);}
  static function mode(){$m=setting('mail_transport','php');return in_array($m,['php','api','smtp'],true)?$m:'php';}
  static function smtp(){return self::mode()==='smtp';}
  static function label(){$m=self::mode();return $m==='api'?(self::apis()[setting('mail_api')][0]??'API').' API':($m==='smtp'?'SMTP':'PHP mail');}
  // PHP mail() works out of the box on shared hosting; an email service API key or SMTP gives better delivery
  static function ready(){
    $m=self::mode();
    if($m==='api'){$a=self::apis()[setting('mail_api')]??null;return $a&&setting('mail_api_key')!==''&&self::from()!==''&&($a[3]!=='secret'||setting('mail_api_secret')!=='')&&($a[3]!=='domain'||setting('mail_api_domain')!=='');}
    if($m==='smtp')return setting('smtp_host')!==''&&self::user()!=='';
    return function_exists('mail')&&self::from()!=='';}
  static function user(){return setting('smtp_user',setting('from_email'));}
  static function from(){return setting('from_email',self::smtp()?setting('smtp_user'):(self::mode()==='api'?'':setting('company_email')));}
  static function send($to,$subject,$html,$text,$files=[]){
    if(self::mode()==='api')return self::api($to,$subject,$html,$text,$files);
    $m=new PHPMailer(true);
    if(self::smtp()){
      $m->isSMTP();
      $m->Host=setting('smtp_host');$m->Port=(int)setting('smtp_port','465');
      $m->SMTPAuth=true;$m->Username=self::user();$m->Password=setting('smtp_pass');
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
      if(!$ok)throw new Exception('Your hosting did not accept the email (PHP mail is disabled or the From email is not allowed). Set the From email to a mailbox on your own domain, or connect an email service in Settings → Messaging → Email. ('.$e->getMessage().')');}
    Usage::log('email',$m->Mailer);
    return $m->getLastMessageID();}
  // Send through the chosen service's HTTPS API with only its API key
  private static function api($to,$subject,$html,$text,$files){
    $k=setting('mail_api');$key=setting('mail_api_key');$from=self::from();$name=setting('from_name',setting('company_name'));$rt=setting('reply_to');$bcc=setting('bcc_email');
    $att=[];foreach($files as $fn=>$path)if(is_file($path))$att[]=['name'=>$fn,'b64'=>base64_encode(file_get_contents($path)),'type'=>mime_content_type($path)?:'application/octet-stream'];
    $j=fn($u,$body,$h)=>http_json($u,['json'=>$body,'headers'=>$h,'timeout'=>60]);
    switch($k){
      case 'brevo':$r=$j('https://api.brevo.com/v3/smtp/email',['sender'=>['email'=>$from,'name'=>$name],'to'=>[['email'=>$to]],'subject'=>$subject,'htmlContent'=>$html,'textContent'=>$text]+($rt?['replyTo'=>['email'=>$rt]]:[])+($bcc?['bcc'=>[['email'=>$bcc]]]:[])+($att?['attachment'=>array_map(fn($a)=>['name'=>$a['name'],'content'=>$a['b64']],$att)]:[]),['api-key: '.$key,'Accept: application/json']);$id=$r['data']['messageId']??'';break;
      case 'sendgrid':$r=$j('https://api.sendgrid.com/v3/mail/send',['personalizations'=>[['to'=>[['email'=>$to]]]+($bcc?['bcc'=>[['email'=>$bcc]]]:[])],'from'=>['email'=>$from,'name'=>$name],'subject'=>$subject,'content'=>[['type'=>'text/plain','value'=>$text],['type'=>'text/html','value'=>$html]]]+($rt?['reply_to'=>['email'=>$rt]]:[])+($att?['attachments'=>array_map(fn($a)=>['content'=>$a['b64'],'filename'=>$a['name'],'type'=>$a['type']],$att)]:[]),['Authorization: Bearer '.$key]);$id='sg-'.md5($to.microtime());break;
      case 'mailjet':$r=http_json('https://api.mailjet.com/v3.1/send',['json'=>['Messages'=>[['From'=>['Email'=>$from,'Name'=>$name],'To'=>[['Email'=>$to]],'Subject'=>$subject,'TextPart'=>$text,'HTMLPart'=>$html]+($rt?['ReplyTo'=>['Email'=>$rt]]:[])+($bcc?['Bcc'=>[['Email'=>$bcc]]]:[])+($att?['Attachments'=>array_map(fn($a)=>['ContentType'=>$a['type'],'Filename'=>$a['name'],'Base64Content'=>$a['b64']],$att)]:[])]],'headers'=>['Authorization: Basic '.base64_encode($key.':'.setting('mail_api_secret'))],'timeout'=>60]);$id=$r['data']['Messages'][0]['To'][0]['MessageID']??'';break;
      case 'postmark':$r=$j('https://api.postmarkapp.com/email',['From'=>$name?$name.' <'.$from.'>':$from,'To'=>$to,'Subject'=>$subject,'HtmlBody'=>$html,'TextBody'=>$text,'MessageStream'=>'outbound']+($rt?['ReplyTo'=>$rt]:[])+($bcc?['Bcc'=>$bcc]:[])+($att?['Attachments'=>array_map(fn($a)=>['Name'=>$a['name'],'Content'=>$a['b64'],'ContentType'=>$a['type']],$att)]:[]),['X-Postmark-Server-Token: '.$key,'Accept: application/json']);$id=$r['data']['MessageID']??'';break;
      case 'resend':$r=$j('https://api.resend.com/emails',['from'=>$name?$name.' <'.$from.'>':$from,'to'=>[$to],'subject'=>$subject,'html'=>$html,'text'=>$text]+($rt?['reply_to'=>$rt]:[])+($bcc?['bcc'=>[$bcc]]:[])+($att?['attachments'=>array_map(fn($a)=>['filename'=>$a['name'],'content'=>$a['b64']],$att)]:[]),['Authorization: Bearer '.$key]);$id=$r['data']['id']??'';break;
      case 'zeptomail':$r=$j('https://api.zeptomail.'.(['com'=>'com','eu'=>'eu'][setting('mail_api_region')]??'in').'/v1.1/email',['from'=>['address'=>$from,'name'=>$name],'to'=>[['email_address'=>['address'=>$to]]],'subject'=>$subject,'htmlbody'=>$html,'textbody'=>$text]+($rt?['reply_to'=>[['address'=>$rt]]]:[])+($bcc?['bcc'=>[['email_address'=>['address'=>$bcc]]]]:[])+($att?['attachments'=>array_map(fn($a)=>['content'=>$a['b64'],'mime_type'=>$a['type'],'name'=>$a['name']],$att)]:[]),['Authorization: '.(stripos($key,'zoho-enczapikey')===0?$key:'Zoho-enczapikey '.$key)]);$id=$r['data']['request_id']??'';break;
      case 'mailgun':
        $f=['from'=>$name?$name.' <'.$from.'>':$from,'to'=>$to,'subject'=>$subject,'html'=>$html,'text'=>$text]+($rt?['h:Reply-To'=>$rt]:[])+($bcc?['bcc'=>$bcc]:[]);
        $n=0;foreach($files as $fn=>$path)if(is_file($path))$f['attachment'.($n++?'['.$n.']':'')]=new CURLFile($path,mime_content_type($path)?:'application/octet-stream',$fn);
        $r=http_json('https://api.'.(setting('mail_api_region')==='eu'?'eu.':'').'mailgun.net/v3/'.rawurlencode(setting('mail_api_domain')).'/messages',['body'=>$f,'headers'=>['Authorization: Basic '.base64_encode('api:'.$key)],'timeout'=>60]);$id=$r['data']['id']??'';break;
      case 'smtp2go':$r=$j('https://api.smtp2go.com/v3/email/send',['api_key'=>$key,'sender'=>$name?$name.' <'.$from.'>':$from,'to'=>[$to],'subject'=>$subject,'html_body'=>$html,'text_body'=>$text]+($bcc?['bcc'=>[$bcc]]:[])+($rt?['custom_headers'=>[['header'=>'Reply-To','value'=>$rt]]]:[])+($att?['attachments'=>array_map(fn($a)=>['filename'=>$a['name'],'fileblob'=>$a['b64'],'mimetype'=>$a['type']],$att)]:[]),[]);$id=$r['data']['data']['email_id']??'';break;
      case 'elastic':$r=$j('https://api.elasticemail.com/v4/emails/transactional',['Recipients'=>['To'=>[$to]]+($bcc?['BCC'=>[$bcc]]:[]),'Content'=>['From'=>$name?$name.' <'.$from.'>':$from,'Subject'=>$subject,'Body'=>[['ContentType'=>'HTML','Content'=>$html],['ContentType'=>'PlainText','Content'=>$text]]]+($rt?['ReplyTo'=>$rt]:[])+($att?['Attachments'=>array_map(fn($a)=>['BinaryContent'=>$a['b64'],'Name'=>$a['name'],'ContentType'=>$a['type']],$att)]:[])],['X-ElasticEmail-ApiKey: '.$key]);$id=$r['data']['MessageID']??'';break;
      default:throw new Exception('Choose your email service in Settings → Messaging → Email.');}
    $ok=$r['code']>=200&&$r['code']<300&&!($k==='smtp2go'&&!empty($r['data']['data']['error']));
    Usage::log('email','api_'.$k,1,0,0,null,!$ok);
    if(!$ok){$d=(array)($r['data']??[]);$msg='';foreach([['error','details',0,'message'],['ErrorMessage'],['Messages',0,'Errors',0,'ErrorMessage'],['errors',0,'message'],['data','error'],['message'],['Message'],['Error'],['error']] as $pth){$v=$d;foreach($pth as $x)$v=is_array($v)?($v[$x]??null):null;if(is_string($v)&&$v!==''){$msg=$v;break;}}throw new Exception(self::apis()[$k][0].' refused the email (HTTP '.$r['code'].'): '.($msg!==''?$msg:($r['error']?:mb_substr($r['body'],0,200))).($r['code']===401||$r['code']===403?' – check the API key and that the From email / domain is verified in your '.self::apis()[$k][0].' account.':''));}
    return $id?:'api-'.token(6);}
  static function html($text,$pixel=''){
    $acc=setting('brand_accent','#B8860B');
    $t=nl2br(e($text));
    $t=preg_replace('~(https?://[^\s<]+)~','<a href="$1" style="color:'.$acc.'">$1</a>',$t);
    [$lf,$chip]=Report::logoPick('#FFFFFF');$logo=$lf?base_url($lf):'';
    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1a1a1a;max-width:600px">'.$t.'<br><br><table cellpadding="0" cellspacing="0" style="border-top:2px solid '.$acc.';padding-top:10px"><tr>'.($logo?'<td style="'.($chip?'background:'.$chip.';padding:8px;border-radius:6px':'padding:0 4px 0 0').'"><img src="'.e($logo).'" height="48" alt=""></td>':'').'<td style="padding-left:12px;font-size:12px;color:#555"><b style="color:#0b0b0b">'.e(setting('company_name')).'</b><br>'.e(setting('company_phone')).' · '.e(setting('company_website')).'</td></tr></table>'.($pixel?'<img src="'.e($pixel).'" width="1" height="1" alt="" style="display:block">':'').'</div>';}
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
