<?php
$id=$_GET['id']??'new';
$u=$id==='new'?['id'=>0,'name'=>'','email'=>'','phone'=>'','role'=>'sales','active'=>1]:DB::row('SELECT * FROM users WHERE id=?',[(int)$id]);
if(!$u){flash('User not found.','err');redirect(url('team'));}
if((int)$u['id']===(int)user()['id'])redirect(url('account'));
if($u['role']==='super'&&!is_super())denied('Only a super admin can change another super admin.');
$allowed=is_super()?['super','admin','sales']:['admin','sales'];
$supers=fn()=>(int)DB::val("SELECT COUNT(*) FROM users WHERE role='super' AND active=1 AND id<>?",[(int)$u['id']]);
if(is_post()){
  $a=$_POST['a']??'save';
  try{
    if($a==='delete'&&$u['id']){
      if($u['role']==='super'&&!$supers())throw new Exception('You cannot delete the last super admin.');
      DB::q('UPDATE leads SET assigned_to=NULL WHERE assigned_to=?',[$u['id']]);DB::q('DELETE FROM users WHERE id=?',[$u['id']]);
      flash('User removed. Their leads are now unassigned.');redirect(url('team'));}
    $d=['name'=>trim($_POST['name']??''),'email'=>strtolower(trim($_POST['email']??'')),'phone'=>trim($_POST['phone']??''),'role'=>in_array($_POST['role']??'',$allowed,true)?$_POST['role']:'sales','active'=>empty($_POST['active'])?0:1];
    if($d['name']===''||!filter_var($d['email'],FILTER_VALIDATE_EMAIL))throw new Exception('Enter a name and a valid email.');
    if(DB::val('SELECT id FROM users WHERE email=? AND id<>?',[$d['email'],(int)$u['id']]))throw new Exception('Another user already has this email.');
    if($u['role']==='super'&&($d['role']!=='super'||!$d['active'])&&!$supers())throw new Exception('Keep at least one active super admin.');
    $pw=$_POST['pw']??'';
    if($pw!==''){if(strlen($pw)<8)throw new Exception('Password must be at least 8 characters.');$d['password']=password_hash($pw,PASSWORD_DEFAULT);}
    elseif(!$u['id'])throw new Exception('Set a password for the new user (8+ characters).');
    if($u['id']){DB::update('users',$d,'id=?',[$u['id']]);flash('Saved.');}
    else{$u['id']=DB::insert('users',$d);flash(e($d['name']).' added as '.e(roles()[$d['role']][0]).'. Share the sign-in link and password with them.');}
    if(!$d['active']&&!empty($_POST['release']))DB::q("UPDATE leads SET assigned_to=NULL WHERE assigned_to=? AND status NOT IN ('won','lost')",[$u['id']]);
    redirect(url('team'));}
  catch(Throwable $e){flash(e($e->getMessage()),'err');redirect(url('user',['id'=>$u['id']?:'new']));}}
$title=$u['id']?$u['name']:'Add team member';$subtitle=$u['id']?'Edit team member':'Invite someone to Setu';
$rc=['super'=>'violet','admin'=>'blue','sales'=>'green'];
$open=$u['id']?(int)DB::val("SELECT COUNT(*) FROM leads WHERE assigned_to=? AND status NOT IN ('won','lost')",[$u['id']]):0;
?>
<a class="back" href="<?=url('team')?>">← Team &amp; Roles</a>
<form method="post" class="g3r mt"><?=csrf_field()?><input type="hidden" name="a" value="save">
<div class="col"><div class="card"><div class="sec"><span class="hi c-indigo"><?=icon('user')?></span><h3>Profile</h3></div><div class="fg"><label>Full name<input name="name" value="<?=e($u['name'])?>" required autofocus></label><label>Email (used to sign in)<input name="email" type="email" value="<?=e($u['email'])?>" required></label><label>Phone<input name="phone" type="tel" value="<?=e($u['phone'])?>"></label><label><?=$u['id']?'Reset password':'Password'?><input name="pw" type="password" minlength="8" autocomplete="new-password" placeholder="<?=$u['id']?'Leave empty to keep current':'At least 8 characters'?>" <?=$u['id']?'':'required'?>></label></div></div>
<div class="card"><div class="sec"><span class="hi c-violet"><?=icon('shield')?></span><h3>Role</h3></div><div class="g31 mb0"><?php foreach(roles() as $k=>[$lb,,$ds]):if(!in_array($k,$allowed,true))continue;?><label class="card c-<?=$rc[$k]?> rolec" style="margin:0;cursor:pointer;box-shadow:none"><span class="row nw" style="align-items:flex-start"><input type="radio" name="role" value="<?=$k?>" <?=$u['role']===$k?'checked':''?> style="margin-top:3px"><span><b style="color:var(--c)"><?=e($lb)?></b><small style="margin-top:4px"><?=e($ds)?></small></span></span></label><?php endforeach?></div><?php if(!is_super()):?><p class="hint">Only a super admin can create another super admin.</p><?php endif?></div></div>
<div class="col"><div class="card" style="position:sticky;top:86px"><div class="sec"><span class="hi c-green"><?=icon('check')?></span><h3>Access</h3></div><label class="chk"><span class="sw"><input type="checkbox" name="active" value="1" <?=$u['active']?'checked':''?>><i></i></span> Can sign in</label><p class="hint mt0">Deactivate instead of deleting to keep their history.</p><?php if($u['id']&&$open):?><label class="chk mt"><input type="checkbox" name="release" value="1"> When deactivated, unassign their <?=$open?> open lead(s)</label><?php endif?><p class="hint">Sign-in link: <a class="lnk" href="#" data-copy="<?=e(base_url('index.php?r=login'))?>">copy</a></p><div class="card-f"><button class="btn btn-p"><?=icon('check')?><?=$u['id']?'Save':'Add member'?></button><a class="btn" href="<?=url('team')?>">Cancel</a></div></div>
<?php if($u['id']):?><div class="card"><div class="sec"><span class="hi c-rose"><?=icon('trash')?></span><h3>Remove</h3></div><p class="sm muted">Deletes the login. Their <?=$open?> open lead(s) become unassigned; history stays.</p><button class="btn btn-r" name="a" value="delete" formnovalidate onclick="return confirm('Remove <?=e(addslashes($u['name']))?> from Setu?')"><?=icon('trash')?>Remove user</button></div><?php endif?></div></form>
