<?php
$title='New lead';$subtitle='Add a lead by hand or import many from a spreadsheet';
$adm=can('settings');$tab=($_GET['tab']??'')==='import'&&$adm?'import':'add';
if(is_post()){
  $a=$_POST['a']??'';
  try{
    if($a==='add'){
      $d=array_intersect_key(array_map(fn($v)=>is_string($v)?trim($v):$v,$_POST),array_flip(CRM::CORE));
      if(($d['name']??'')===''&&($d['contact_name']??'')==='')throw new Exception('Enter the contact person or the business name.');
      if(($d['phone']??'')===''&&($d['email']??'')==='')throw new Exception('Enter a phone number or an email so you can reach this lead.');
      if(!empty($d['follow_up_at']))$d['follow_up_at']=date('Y-m-d H:i:s',strtotime($d['follow_up_at']));
      $id=CRM::create($d,'manual',user()['name']??'',[]);
      CRM::saveCustom($id,$_POST['cf']??[]);
      $own=$adm?((int)($_POST['assigned_to']??0)?:null):user()['id'];
      DB::update('leads',['assigned_to'=>$own,'notes'=>trim($_POST['notes']??'')],'id=?',[$id]);
      if(!empty($_POST['status'])&&$_POST['status']!=='new')CRM::setStatus($id,$_POST['status']);
      flash('Lead added.');redirect(!empty($_POST['another'])?url('lead_new'):url('lead',['id'=>$id]));}
    if($a==='import'&&$adm){
      if(empty($_FILES['csv']['tmp_name'])||!is_uploaded_file($_FILES['csv']['tmp_name']))throw new Exception('Choose a CSV file.');
      flash(CRM::import($_FILES['csv']['tmp_name']).' lead(s) imported.');redirect(url('leads',['src'=>'import']));}
  }catch(Throwable $e){flash(e($e->getMessage()),'err');$_SESSION['old']=$_POST;}
  redirect(url('lead_new',['tab'=>$tab]));}
$o=$_SESSION['old']??[];unset($_SESSION['old']);
$v=fn($k,$d='')=>e($o[$k]??$d);
$users=$adm?DB::all('SELECT id,name FROM users WHERE active=1 ORDER BY name'):[];
$cf=CRM::fields();
?>
<a class="back" href="<?=url('leads')?>">← Leads</a>
<div class="tabs mt"><a href="<?=url('lead_new')?>" class="<?=$tab==='add'?'on':''?>"><?=icon('user')?>Add manually</a><?php if($adm):?><a href="<?=url('lead_new',['tab'=>'import'])?>" class="<?=$tab==='import'?'on':''?>"><?=icon('upload')?>Import CSV</a><?php endif?></div>
<?php if($tab==='add'):?>
<form method="post" class="g3r"><?=csrf_field()?><input type="hidden" name="a" value="add">
<div class="col"><div class="card"><div class="sec"><span class="hi c-indigo"><?=icon('user')?></span><h3>Contact <small>– who are you talking to?</small></h3></div><div class="fg"><label>Contact person<input name="contact_name" value="<?=$v('contact_name')?>" placeholder="Full name" autofocus></label><label>Business / company<input name="name" value="<?=$v('name')?>" placeholder="Company name"></label><label>Phone / WhatsApp<input name="phone" type="tel" value="<?=$v('phone')?>" placeholder="+91 98xxx xxxxx"></label><label>Email<input name="email" type="email" value="<?=$v('email')?>" placeholder="name@company.com"></label><label>Alternate phone<input name="alt_phone" type="tel" value="<?=$v('alt_phone')?>"></label><label>Website<input name="website" value="<?=$v('website')?>" placeholder="example.com"></label></div></div>
<div class="card"><div class="sec"><span class="hi c-teal"><?=icon('pin')?></span><h3>Business details</h3></div><div class="fg"><label>City<input name="city" value="<?=$v('city')?>"></label><label>Category / industry<input name="category" value="<?=$v('category')?>" placeholder="e.g. Dental clinic"></label><label class="full">Address<input name="address" value="<?=$v('address')?>"></label></div><?php if($cf):?><div class="sec mt"><span class="hi c-violet"><?=icon('list')?></span><h3>More fields</h3><?php if($adm):?><a class="lnk sm" style="margin-left:auto" href="<?=url('settings',['tab'=>'fields'])?>">Manage fields</a><?php endif?></div><div class="fg"><?php foreach($cf as $f):?><label class="<?=$f['type']==='textarea'?'full':''?>"><?=e($f['label'])?><?=$f['required']?' *':''?><?=CRM::input($f,$o['cf'][$f['fkey']]??'')?></label><?php endforeach?></div><?php endif?></div>
<div class="card"><div class="sec"><span class="hi c-amber"><?=icon('msg')?></span><h3>Enquiry &amp; notes</h3></div><label>What did they ask for?<textarea name="message" rows="3" placeholder="Their requirement in their words"><?=$v('message')?></textarea></label><label class="mb0">Internal notes<textarea name="notes" rows="3" placeholder="Only your team sees this"><?=$v('notes')?></textarea></label></div></div>
<div class="col"><div class="card" style="position:sticky;top:86px"><div class="sec"><span class="hi c-green"><?=icon('target')?></span><h3>Deal</h3></div><label>Status<select name="status"><?php foreach(crm_statuses() as $kk=>[$lb]):?><option value="<?=$kk?>" <?=($o['status']??'new')===$kk?'selected':''?>><?=e($lb)?></option><?php endforeach?></select></label><label>Service interested in<input name="service" value="<?=$v('service')?>" list="svc"><datalist id="svc"><?php foreach(array_filter(array_map('trim',explode("\n",setting('services')))) as $s):?><option value="<?=e($s)?>"><?php endforeach?></datalist></label><label>Deal value (<?=e(setting('currency_symbol','₹'))?>)<input name="deal_value" type="number" step="0.01" min="0" value="<?=$v('deal_value')?>"></label><?php if($users):?><label>Owner<select name="assigned_to"><option value="">Unassigned</option><?php foreach($users as $u):?><option value="<?=$u['id']?>" <?=(int)($o['assigned_to']??user()['id'])===(int)$u['id']?'selected':''?>><?=e($u['name'])?></option><?php endforeach?></select></label><?php endif?><label>Follow-up<input name="follow_up_at" type="datetime-local" value="<?=$v('follow_up_at')?>"></label><label>Tags<input name="tags" value="<?=$v('tags')?>" placeholder="hot, referral"><small>Separate with commas. Tags can start automations.</small></label><div class="card-f"><button class="btn btn-p"><?=icon('check')?>Save lead</button><button class="btn" name="another" value="1"><?=icon('plus')?>Save &amp; add another</button></div></div></div></form>
<?php else:?>
<div class="g3r"><form method="post" enctype="multipart/form-data" class="card"><?=csrf_field()?><input type="hidden" name="a" value="import"><div class="sec"><span class="hi c-blue"><?=icon('upload')?></span><h3>Import leads from a CSV file</h3></div><label>CSV file<input type="file" name="csv" accept=".csv,text/csv" required><small>Excel / Google Sheets → File → Download / Save as → CSV.</small></label><div class="card-f"><button class="btn btn-p"><?=icon('upload')?>Import</button><a class="btn" href="<?=url('leads')?>">Cancel</a></div></form>
<div class="card"><div class="sec"><span class="hi c-amber"><?=icon('book')?></span><h3>How columns are matched</h3></div><p class="sm">The first row must contain column names. They are matched automatically:</p><table class="tbl sm"><tbody><?php foreach(['Contact person'=>'name, full name, first name, contact','Business'=>'company, business, organisation','Phone'=>'phone, mobile, whatsapp, contact number','Email'=>'email, e-mail','City'=>'city, location, town','Service'=>'service, interested in, product','Deal value'=>'budget, amount, value','Notes'=>'message, comments, requirement'] as $k=>$x):?><tr><td><b><?=$k?></b></td><td class="muted"><?=$x?></td></tr><?php endforeach?></tbody></table><p class="hint">Your custom field keys are matched too. Other columns are kept in the lead's notes. Leads with the same phone or email in the last 30 days are merged, not duplicated.</p></div></div>
<?php endif?>
