<?php
$title='My account';$subtitle='Your profile and password';
$u=DB::row('SELECT * FROM users WHERE id=?',[user()['id']]);
if(is_post()){
  try{
    if(!password_verify($_POST['old']??'',$u['password']))throw new Exception('Your current password is not correct.');
    $d=['name'=>trim($_POST['name']??''),'email'=>strtolower(trim($_POST['email']??'')),'phone'=>trim($_POST['phone']??'')];
    if($d['name']===''||!filter_var($d['email'],FILTER_VALIDATE_EMAIL))throw new Exception('Enter your name and a valid email.');
    if(DB::val('SELECT id FROM users WHERE email=? AND id<>?',[$d['email'],$u['id']]))throw new Exception('Another user already has this email.');
    if(($_POST['new']??'')!==''){if(strlen($_POST['new'])<8)throw new Exception('New password must be at least 8 characters.');if($_POST['new']!==($_POST['new2']??''))throw new Exception('The two new passwords do not match.');$d['password']=password_hash($_POST['new'],PASSWORD_DEFAULT);}
    DB::update('users',$d,'id=?',[$u['id']]);flash('Account updated.');}
  catch(Throwable $e){flash(e($e->getMessage()),'err');}
  redirect(url('account'));}
$S=' AND l.assigned_to='.(int)$u['id'];
$k=DB::row("SELECT SUM(l.status NOT IN ('won','lost')) op,SUM(l.status='won') wn,SUM(CASE WHEN l.status='won' THEN l.deal_value ELSE 0 END) wv,SUM(l.follow_up_at IS NOT NULL AND l.follow_up_at<=NOW() AND l.status NOT IN ('won','lost')) due FROM leads l WHERE l.is_lead=1$S");
?>
<div class="card hero"><div class="lh"><span class="avc" style="background:rgba(255,255,255,.15);color:#fff"><?=e(mb_strtoupper(mb_substr($u['name'],0,1)))?></span><div class="grow"><h2><?=e($u['name'])?></h2><div class="meta" style="color:#C7CCF5"><span><?=icon('mail')?><?=e($u['email'])?></span><span><?=icon('shield')?><?=e(roles()[$u['role']][0]??$u['role'])?></span><?php if($u['last_login']):?><span><?=icon('clock')?>last sign-in <?=ago($u['last_login'])?></span><?php endif?></div></div></div></div>
<div class="stats"><?=kpi('My open leads',(int)$k['op'],'users','indigo','',url('leads',['own'=>'me']))?><?=kpi('Follow-ups due',(int)$k['due'],'clock','orange','',url('leads',['own'=>'me','fu'=>'due']))?><?=kpi('Won by me',money($k['wv']),'target','green',(int)$k['wn'].' deals')?></div>
<form method="post" class="g3r"><?=csrf_field()?><div class="card"><div class="sec"><span class="hi c-blue"><?=icon('user')?></span><h3>Profile</h3></div><div class="fg"><label>Name<input name="name" value="<?=e($u['name'])?>" required></label><label>Email<input name="email" type="email" value="<?=e($u['email'])?>" required></label><label>Phone<input name="phone" value="<?=e($u['phone'])?>"></label></div><div class="sec mt"><span class="hi c-violet"><?=icon('shield')?></span><h3>Change password <small>– optional</small></h3></div><div class="fg"><label>New password<input type="password" name="new" minlength="8" autocomplete="new-password"></label><label>Repeat new password<input type="password" name="new2" minlength="8" autocomplete="new-password"></label></div></div>
<div class="card"><div class="sec"><span class="hi c-green"><?=icon('check')?></span><h3>Confirm</h3></div><label>Current password<input type="password" name="old" required autocomplete="current-password"><small>Needed to save any change.</small></label><div class="card-f"><button class="btn btn-p"><?=icon('check')?>Save changes</button></div></div></form>
