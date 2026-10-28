<?php
// Public lead capture endpoint: hook.php?k=SOURCE_TOKEN
require __DIR__.'/app/bootstrap.php';
session_write_close();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS')exit;
$k=preg_replace('/[^a-f0-9]/','',$_GET['k']??'');
$src=$k?DB::row('SELECT * FROM sources WHERE token=? AND active=1',[$k]):null;
if(!$src){http_response_code(404);exit('Unknown or disabled source');}
if(!throttle('h:'.($_SERVER['REMOTE_ADDR']??''),60,10)){http_response_code(429);json_out(['ok'=>false,'error'=>'Too many submissions – please try again in a few minutes.'],429);}
try{$ids=Capture::handle($src);}
catch(Throwable $e){
  // Never fail the visitor's form: answer 200, record the real reason on the source (shown in Settings → Lead capture)
  $m=$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')';logmsg('Capture '.$src['id'].': '.$m);
  try{$c=jd(DB::val('SELECT config FROM sources WHERE id=?',[$src['id']]));$c['last_error']=[date('Y-m-d H:i:s'),mb_substr($m,0,400)];DB::update('sources',['config'=>je($c)],'id=?',[$src['id']]);}catch(Throwable $x){}
  json_out(['ok'=>false,'error'=>'The enquiry could not be saved.']);}
// Server-to-server calls (Elementor/WordPress, Zapier, Meta, Google) get JSON; a browser form post gets the thank-you page
$ua=$_SERVER['HTTP_USER_AGENT']??'';
$ajax=stripos($_SERVER['HTTP_ACCEPT']??'','json')!==false||stripos($_SERVER['CONTENT_TYPE']??'','json')!==false||!empty($_SERVER['HTTP_X_REQUESTED_WITH'])||$src['type']!=='website'||stripos($ua,'Mozilla')===false;
if($ajax){json_out(['ok'=>true,'leads'=>count($ids)]);}
$cfg=jd($src['config']);
// Redirect only to the saved thank-you page, or a page on the site the form was posted from (no open redirect)
$to=$cfg['thanks_url']??'';$rd=(string)($_POST['redirect']??'');
if($rd!==''&&preg_match('~^https?://~i',$rd)&&parse_url($rd,PHP_URL_HOST)===parse_url((string)($_SERVER['HTTP_REFERER']??''),PHP_URL_HOST))$to=$rd;
if($to&&preg_match('~^https?://~i',$to))redirect($to);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Thank you</title></head><body style="font-family:Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#f5f7fb;margin:0"><div style="background:#fff;padding:40px;border-radius:14px;text-align:center;max-width:420px;box-shadow:0 10px 30px rgba(0,0,0,.08)"><div style="font-size:44px">✅</div><h2 style="margin:10px 0"><?=e($cfg['thanks_title']??'Thank you!')?></h2><p style="color:#555"><?=e($cfg['thanks_text']??'We have received your enquiry and will contact you shortly.')?></p><?php if(!empty($_SERVER['HTTP_REFERER'])):?><a href="<?=e(safe_url($_SERVER['HTTP_REFERER']))?>" style="color:#0B2545">← Back</a><?php endif?></div></body></html>
<?php Cron::kick();
