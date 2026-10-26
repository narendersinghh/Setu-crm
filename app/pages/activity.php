<?php
$title='All activity';$subtitle='Everything that happened across your leads';
$pro=mod('prospecting')&&can('prospecting');$S=scope();
$ty=preg_replace('/[^a-z_]/','',$_GET['type']??'');
$w='1=1'.($pro?'':' AND l.is_lead=1').$S.($ty?' AND e.type='.DB::pdo()->quote($ty):'');
$per=50;$pg=max(1,(int)($_GET['p']??1));
$total=(int)DB::val("SELECT COUNT(*) FROM events e JOIN leads l ON l.id=e.lead_id WHERE $w");
$ev=DB::all("SELECT e.*,l.name,l.contact_name FROM events e JOIN leads l ON l.id=e.lead_id WHERE $w ORDER BY e.id DESC LIMIT $per OFFSET ".(($pg-1)*$per));
$pages=max(1,(int)ceil($total/$per));
$evc=['found'=>'pin','enriched'=>'globe','audited'=>'activity','stage'=>'zap','sent'=>'send','opened'=>'eye','viewed'=>'eye','replied'=>'msg','error'=>'alert','note'=>'edit','scheduled'=>'clock','wa_manual'=>'wa','call'=>'mic','auto'=>'bot','booked'=>'cal'];
$types=['found'=>'New leads','stage'=>'Status changes','sent'=>'Messages sent','replied'=>'Replies','booked'=>'Bookings','call'=>'Calls','note'=>'Notes','error'=>'Errors'];
?>
<a class="back" href="<?=url('dashboard')?>">← Dashboard</a>
<div class="tabs mt"><a href="<?=url('activity')?>" class="<?=$ty===''?'on':''?>">All<em><?=$ty===''?$total:''?></em></a><?php foreach($types as $k=>$lb):?><a href="<?=url('activity',['type'=>$k])?>" class="<?=$ty===$k?'on':''?>"><?=e($lb)?></a><?php endforeach?></div>
<div class="card"><?php if(!$ev):?><div class="empty sm"><?=icon('activity')?><p>No activity here yet.</p></div><?php else:?><ul class="tl" style="margin-top:0"><?php foreach($ev as $x):?><li class="ev-<?=e($x['type'])?>"><i><?=icon($evc[$x['type']]??'zap')?></i><div><span><a href="<?=url('lead',['id'=>$x['lead_id']])?>"><b><?=e($x['contact_name']?:$x['name'])?></b></a> · <?=e($x['message'])?></span><small><?=e(date('d M Y, h:i A',strtotime($x['created_at'])))?><?=$x['channel']?' · '.e($x['channel']):''?></small></div></li><?php endforeach?></ul><?php endif?>
<?php if($pages>1):?><div class="pg" style="margin:14px -20px -20px"><span class="muted"><?=number_format($total)?> events</span><?php for($i=max(1,$pg-3);$i<=min($pages,$pg+3);$i++):?><a href="<?=url('activity',array_filter(['type'=>$ty,'p'=>$i]))?>" class="<?=$i===$pg?'on':''?>"><?=$i?></a><?php endfor?></div><?php endif?></div>
