<?php
if(isset($_GET['logout'])){if(hash_equals(csrf(),(string)($_GET['t']??''))){$_SESSION=[];session_destroy();}redirect('index.php?r=login');}
if(user())redirect('index.php');
$err='';
if(is_post()){
  // Brute-force guard per IP address (a new browser session does not reset it): 10 failures → 15 minutes wait
  $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,45);
  if((int)DB::val('SELECT n FROM login_fails WHERE ip=? AND last>NOW()-INTERVAL 15 MINUTE',[$ip])>=10){$err='Too many failed attempts. Please wait 15 minutes and try again.';}
  else{
    $u=DB::row('SELECT * FROM users WHERE email=?',[trim($_POST['email']??'')]);
    if($u&&password_verify($_POST['password']??'',$u['password'])){
      if(isset($u['active'])&&!$u['active'])$err='Your account has been deactivated. Ask your admin to switch it on.';
      else{session_regenerate_id(true);$_SESSION['uid']=$u['id'];DB::q('DELETE FROM login_fails WHERE ip=?',[$ip]);try{DB::update('users',['last_login'=>date('Y-m-d H:i:s')],'id=?',[$u['id']]);}catch(Throwable $e){}redirect('index.php');}}
    else{$err='Invalid email or password.';DB::q('INSERT INTO login_fails (ip,n,last) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE n=IF(last<NOW()-INTERVAL 15 MINUTE,1,n+1),last=NOW()',[$ip]);}}}
$bare=true;$title='Sign in';?>
<div class="auth"><div class="auth-l"><img class="logo-i lg" src="assets/img/setu-logo.png" alt="Setu"><h2>Setu</h2><p>The bridge between you and your next customer. Find businesses on Google Maps, send AI growth reports, capture leads from forms &amp; ads, and follow up automatically.</p><ul><li><span class="ni c-orange"><?=icon('map')?></span>Google Maps prospecting &amp; audits</li><li><span class="ni c-violet"><?=icon('spark')?></span>Value-first AI reports as branded PDFs</li><li><span class="ni c-green"><?=icon('users')?></span>CRM with forms, Meta &amp; Google Ads leads</li><li><span class="ni c-teal"><?=icon('cal')?></span>Bookings synced with Google Calendar</li><li><span class="ni c-pink"><?=icon('bot')?></span>WhatsApp, email &amp; AI calls on autopilot</li></ul></div><form class="auth-r" method="post"><?=csrf_field()?><h1>Welcome back</h1><p class="muted">Sign in to continue</p><?php if($err):?><div class="al al-err"><?=icon('alert')?><span><?=e($err)?></span></div><?php endif?><label>Email<input type="email" name="email" required autofocus value="<?=e($_POST['email']??'')?>"></label><label>Password<input type="password" name="password" required></label><button class="btn btn-p btn-b">Sign in</button></form></div>
