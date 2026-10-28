<?php
use PHPMailer\PHPMailer\PHPMailer;
final class Mailer{
  // Ready-made SMTP settings: [name, host, port, encryption, fixed username, username hint, password/key hint, where to get it]
  static function providers(){return [
    'custom'=>['Custom / my own mailbox','','','','','Usually your full email address','Mailbox password',''],
    'brevo'=>['Brevo (Sendinblue)','smtp-relay.brevo.com','587','tls','','SMTP login shown in Brevo (e.g. 8a1b2c001@smtp-brevo.com)','SMTP key (starts with xsmtpsib-)','https://app.brevo.com/settings/keys/smtp'],
    'mailjet'=>['Mailjet','in-v3.mailjet.com','587','tls','','API key','Secret key','https://app.mailjet.com/account/apikeys'],
    'sendgrid'=>['SendGrid','smtp.sendgrid.net','587','tls','apikey','Always "apikey"','API key (starts with SG.)','https://app.sendgrid.com/settings/api_keys'],
    'mailgun'=>['Mailgun','smtp.mailgun.org','587','tls','','SMTP login, e.g. postmaster@mg.yourdomain.com (EU accounts: host smtp.eu.mailgun.org)','SMTP password of that login','https://app.mailgun.com/mg/sending/domains'],
    'ses'=>['Amazon SES','email-smtp.ap-south-1.amazonaws.com','587','tls','','SMTP username from SES → SMTP settings (change the region in the host if needed)','SMTP password (not your AWS secret key)','https://console.aws.amazon.com/ses/home#/smtp'],
    'zeptomail'=>['Zoho ZeptoMail','smtp.zeptomail.in','587','tls','emailapikey','Always "emailapikey" (use smtp.zeptomail.com outside India)','Send Mail token','https://www.zoho.com/zeptomail/'],
    'postmark'=>['Postmark','smtp.postmarkapp.com','587','tls','','Server API token (same as password)','Server API token','https://account.postmarkapp.com/servers'],
    'resend'=>['Resend','smtp.resend.com','465','ssl','resend','Always "resend"','API key (starts with re_)','https://resend.com/api-keys'],
    'smtp2go'=>['SMTP2GO','mail.smtp2go.com','587','tls','','SMTP username created in SMTP2GO','SMTP user password','https://app.smtp2go.com/settings/users/'],
    'elastic'=>['Elastic Email','smtp.elasticemail.com','2525','tls','','Your Elastic Email account email','SMTP / API key','https://app.elasticemail.com/marketing/settings/new/create-smtp'],
    'gmail'=>['Gmail / Google Workspace','smtp.gmail.com','587','tls','','Your Gmail / Workspace address','App password (Google Account → Security → App passwords)','https://myaccount.google.com/apppasswords'],
    'zoho'=>['Zoho Mail','smtp.zoho.in','465','ssl','','Your Zoho mailbox address (smtp.zoho.com outside India)','Mailbox or app-specific password','https://accounts.zoho.in/home#security/app_password'],
    'outlook'=>['Microsoft 365 / Outlook','smtp.office365.com','587','tls','','Your Microsoft 365 address','Password or app password (SMTP AUTH must be enabled)',''],
    'hostinger'=>['Hostinger email','smtp.hostinger.com','465','ssl','','Your full mailbox address','Mailbox password','']];}
  // Fill host/port/encryption (and fixed usernames) from the chosen provider when left empty
  static function preset($k){
    $p=self::providers()[$k]??null;if(!$p||$k==='custom')return;
    // Only empty fields are filled, so a changed region/host (Mailgun EU, SES region…) is kept
    if(setting('smtp_host')===''){Settings::many(['smtp_host'=>$p[1],'smtp_port'=>$p[2],'smtp_secure'=>$p[3]]);}
    if($p[4]!==''&&setting('smtp_user')==='')Settings::set('smtp_user',$p[4]);
    if($k==='postmark'&&setting('smtp_user')===''&&setting('smtp_pass')!=='')Settings::set('smtp_user',setting('smtp_pass'));}
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
