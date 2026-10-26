<?php
$id=$_GET['id']??'new';
$au=$id==='new'?['id'=>0,'name'=>'','trig'=>'lead_created','trig_value'=>'','conditions'=>'[]','steps'=>'[]','stop_on_reply'=>1,'active'=>0]:DB::row('SELECT * FROM automations WHERE id=?',[(int)$id]);
if(!$au){flash('Automation not found.','err');redirect(url('automations'));}
$T=Automation::triggers();$AC=Automation::actions();
if(is_post()){
  $steps=json_decode($_POST['steps']??'[]',true)?:[];$conds=json_decode($_POST['conds']??'[]',true)?:[];
  $trig=isset($T[$_POST['trig']??''])?$_POST['trig']:'lead_created';
  $tv=trim($_POST['tv_'.$trig]??'');
  $d=['name'=>mb_substr(trim($_POST['name']??'')?:'Untitled automation',0,150),'trig'=>$trig,'trig_value'=>$tv,'conditions'=>je(array_values(array_filter($conds,fn($c)=>($c['f']??'')!==''))),'steps'=>je($steps),'stop_on_reply'=>empty($_POST['stop_on_reply'])?0:1,'active'=>empty($_POST['active'])?0:1];
  if(!$steps)flash('Add at least one step.','warn');
  if($au['id'])DB::update('automations',$d,'id=?',[$au['id']]);else $au['id']=DB::insert('automations',$d);
  flash('Automation saved'.($d['active']?' and switched on.':' (off – switch it on when ready).'));
  redirect(url('automation',['id'=>$au['id']]));}
$title=$au['id']?$au['name']:'New automation';$subtitle='Trigger → conditions → steps';
$steps=jd($au['steps']);$conds=jd($au['conditions']);
$users=DB::all('SELECT id,name FROM users ORDER BY name');
$runs=$au['id']?DB::all('SELECT r.*,l.name,l.contact_name FROM automation_runs r JOIN leads l ON l.id=r.lead_id WHERE r.automation_id=? ORDER BY r.id DESC LIMIT 30',[$au['id']]):[];
$vars='{{first_name}} {{contact_name}} {{business_name}} {{phone}} {{email}} {{city}} {{service}} {{booking_link}} {{report_link}} {{agency_name}} {{sender_name}}';
// One editable card per step type
$card=function($t,$s=[])use($AC,$users){
  $x=$AC[$t];$v=fn($k,$d='')=>e($s[$k]??$d);
  $o='<div class="au-st" data-t="'.$t.'"><span class="hi hi-'.['green'=>'g','blue'=>'b','purple'=>'p','orange'=>'o','gray'=>'n','teal'=>'t','red'=>'r','gold'=>'y'][$x[2]].'">'.icon($x[1]).'</span><div class="grow"><b>'.e($x[0]).'</b>';
  switch($t){
    case 'wait':$o.='<div class="row mt"><input type="number" min="1" data-k="n" value="'.$v('n',1).'" class="sm-in"><select data-k="u" style="max-width:140px">'.implode('',array_map(fn($u)=>'<option '.(($s['u']??'hours')===$u?'selected':'').'>'.$u.'</option>',['minutes','hours','days'])).'</select></div>';break;
    case 'whatsapp':$o.='<textarea data-k="text" rows="4" class="mt" placeholder="Message…">'.$v('text').'</textarea><label class="chk sm mt"><input type="checkbox" data-k="attach" '.(!empty($s['attach'])?'checked':'').'> Attach the audit PDF (if a report exists)</label>';break;
    case 'email':$o.='<input data-k="subject" class="mt" placeholder="Subject" value="'.$v('subject').'"><textarea data-k="body" rows="5" class="mt" placeholder="Email text…">'.$v('body').'</textarea><label class="chk sm mt"><input type="checkbox" data-k="attach" '.(!empty($s['attach'])?'checked':'').'> Attach the audit PDF (if a report exists)</label>';break;
    case 'call':$o.='<input data-k="first" class="mt" placeholder="First sentence (blank = default from Settings)" value="'.$v('first').'"><textarea data-k="task" rows="3" class="mt" placeholder="What should the AI do on this call? (blank = default script)">'.$v('task').'</textarea>';break;
    case 'booking':$o.='<div class="row mt"><select data-k="ch" style="max-width:160px"><option value="whatsapp" '.(($s['ch']??'')==='whatsapp'?'selected':'').'>via WhatsApp</option><option value="email" '.(($s['ch']??'')==='email'?'selected':'').'>via Email</option></select><input data-k="subject" placeholder="Email subject" value="'.$v('subject').'"></div><textarea data-k="text" rows="3" class="mt" placeholder="Message (the booking link is added automatically)">'.$v('text').'</textarea>';break;
    case 'status':$o.='<select data-k="v" class="mt">'.implode('',array_map(fn($k,$l)=>'<option value="'.$k.'" '.(($s['v']??'')===$k?'selected':'').'>'.e($l[0]).'</option>',array_keys(crm_statuses()),crm_statuses())).'</select>';break;
    case 'tag':$o.='<input data-k="v" class="mt" placeholder="tag name" value="'.$v('v').'">';break;
    case 'assign':$o.='<select data-k="v" class="mt">'.implode('',array_map(fn($u)=>'<option value="'.$u['id'].'" '.((int)($s['v']??0)===(int)$u['id']?'selected':'').'>'.e($u['name']).'</option>',$users)).'</select>';break;
    case 'followup':$o.='<div class="row mt"><span class="sm muted">in</span><input type="number" min="1" data-k="n" value="'.$v('n',1).'" class="sm-in"><select data-k="u" style="max-width:120px"><option '.(($s['u']??'days')==='days'?'selected':'').'>days</option><option '.(($s['u']??'')==='hours'?'selected':'').'>hours</option></select></div>';break;
    case 'notify':$o.='<input data-k="to" class="mt" placeholder="Email to notify (blank = company email)" value="'.$v('to').'"><input data-k="subject" class="mt" placeholder="Subject" value="'.$v('subject','New lead – {{contact_name}}').'"><textarea data-k="text" rows="2" class="mt">'.$v('text','{{contact_name}} – {{phone}} – {{email}}').'</textarea>';break;
    case 'report':$o.='<label class="chk sm mt"><input type="checkbox" data-k="contact" '.(!empty($s['contact'])?'checked':'').'> Send the report by Email &amp; WhatsApp when ready</label>';break;
    case 'webhook':$o.='<input data-k="url" class="mt" placeholder="https://hooks.zapier.com/…" value="'.$v('url').'"><small class="muted">Sends the lead as JSON (Zapier, Make, n8n, Google Sheets…)</small>';break;}
  return $o.'</div><div class="row" style="flex-direction:column;gap:2px"><button type="button" class="ib" data-step="up" title="Move up">↑</button><button type="button" class="ib" data-step="down" title="Move down">↓</button><button type="button" class="ib red" data-step="del" title="Remove">'.icon('trash').'</button></div></div>';};
$condRow=function($c=[]){$f=$c['f']??'';$op=$c['op']??'is';$o='<div class="row cond mt"><select data-k="f" style="max-width:150px">';foreach(Automation::conds() as $k=>$l)$o.='<option value="'.$k.'" '.($f===$k?'selected':'').'>'.$l.'</option>';foreach(CRM::fields() as $cf)$o.='<option value="custom.'.e($cf['fkey']).'" '.($f==='custom.'.$cf['fkey']?'selected':'').'>'.e($cf['label']).'</option>';$o.='</select><select data-k="op" style="max-width:150px">';foreach(['is'=>'is','not'=>'is not','contains'=>'contains','empty'=>'is empty','not_empty'=>'is not empty'] as $k=>$l)$o.='<option value="'.$k.'" '.($op===$k?'selected':'').'>'.$l.'</option>';return $o.'</select><input data-k="v" value="'.e($c['v']??'').'" placeholder="value"><button type="button" class="ib red" data-step="del">'.icon('x').'</button></div>';};
?>
<form method="post" id="ab"><?=csrf_field()?><input type="hidden" name="steps" id="steps-json"><input type="hidden" name="conds" id="conds-json">
<div class="ph"><a class="lnk" href="<?=url('automations')?>">← Automations</a><div class="pha"><label class="chk"><span class="sw"><input type="checkbox" name="active" value="1" <?=$au['active']?'checked':''?>><i></i></span> Active</label><button class="btn btn-y"><?=icon('check')?>Save automation</button></div></div>
<div class="g3"><div class="col"><div class="card"><label>Name<input name="name" value="<?=e($au['name'])?>" placeholder="e.g. New Facebook lead – welcome + AI call" required></label>
<div class="au-st trig" style="display:block"><b class="row"><span class="hi hi-y"><?=icon('zap')?></span>When this happens</b><select name="trig" class="mt" data-toggle><?php foreach($T as $k=>[$l]):?><option value="<?=$k?>" <?=$au['trig']===$k?'selected':''?>><?=e($l)?></option><?php endforeach?></select>
<div data-show="trig=lead_created" class="mt"><label>Only for source<select name="tv_lead_created"><option value="">Any source</option><?php foreach(lead_sources() as $k=>[$l]):?><option value="<?=$k?>" <?=$au['trig']==='lead_created'&&$au['trig_value']===$k?'selected':''?>><?=e($l)?></option><?php endforeach?></select></label></div>
<div data-show="trig=status_changed" class="mt"><label>Status<select name="tv_status_changed"><?php foreach(crm_statuses() as $k=>[$l]):?><option value="<?=$k?>" <?=$au['trig']==='status_changed'&&$au['trig_value']===$k?'selected':''?>><?=e($l)?></option><?php endforeach?></select></label></div>
<div data-show="trig=tag_added" class="mt"><label>Tag<input name="tv_tag_added" value="<?=$au['trig']==='tag_added'?e($au['trig_value']):''?>"></label></div>
<div data-show="trig=no_reply" class="mt"><label>Days without reply<input type="number" min="1" name="tv_no_reply" value="<?=$au['trig']==='no_reply'?e($au['trig_value']):'3'?>"></label></div>
<?php foreach($T as $k=>[$l,$desc]):?><p class="hint" data-show="trig=<?=$k?>"><?=e($desc)?></p><?php endforeach?></div>
<h4>Only if (optional)</h4><div id="conds"><?php foreach($conds as $c)echo $condRow($c);?></div><button type="button" class="btn btn-sm mt" onclick="A.addCond()"><?=icon('plus')?>Add condition</button>
<label class="chk mt"><input type="checkbox" name="stop_on_reply" value="1" <?=$au['stop_on_reply']?'checked':''?>> Stop when the lead replies</label>
<p class="hint">Placeholders: <?=e($vars)?></p></div>
<?php if($runs):?><div class="card"><div class="card-h"><h3>Recent runs</h3></div><?php foreach($runs as $r):?><div class="msg"><div class="msg-h"><a class="lnk" href="<?=url('lead',['id'=>$r['lead_id']])?>"><?=e($r['contact_name']?:$r['name'])?></a><span class="bdg bdg-<?=['running'=>'blue','waiting'=>'gold','done'=>'green','failed'=>'red'][$r['status']]??'gray'?>"><?=e($r['status'])?></span><small><?=ago($r['created_at'])?></small></div><?php if($r['log']):?><p style="white-space:pre-wrap"><?=e(trim($r['log']))?></p><?php endif?></div><?php endforeach?></div><?php endif?></div>
<div class="col"><div class="card"><div class="card-h"><h3><span class="hi hi-p"><?=icon('bot')?></span>Then do this</h3><div class="dd"><button type="button" class="btn btn-n btn-sm" onclick="this.parentNode.classList.toggle('open')"><?=icon('plus')?>Add step</button><div class="ddm"><?php foreach($AC as $k=>[$l,$i]):?><button type="button" onclick="A.addStep('<?=$k?>');this.closest('.dd').classList.remove('open')"><?=icon($i)?><?=e($l)?></button><?php endforeach?></div></div></div><div id="steps" class="au-steps"><?php foreach($steps as $s)if(isset($AC[$s['t']??'']))echo $card($s['t'],$s);?></div><?php if(!$steps):?><p class="muted sm">No steps yet – click <b>Add step</b>. Tip: start with a WhatsApp message, then <i>Wait</i>, then a follow-up.</p><?php endif?></div></div></div>
</form>
<?php foreach(array_keys($AC) as $k):?><template id="tpl-<?=$k?>"><?=$card($k)?></template><?php endforeach?><template id="tpl-cond"><?=$condRow()?></template>
