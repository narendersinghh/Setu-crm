<?php
$id=(int)($_GET['id']??$_POST['cid']??0);
$c=DB::row('SELECT * FROM campaigns WHERE id=?',[$id]);
if(!$c){flash('Search not found.','err');redirect(url('scraper'));}
if(is_post()){
  $a=$_POST['a']??'';
  if($a==='stop'){DB::update('campaigns',['status'=>'stopped'],'id=?',[$id]);DB::q("DELETE FROM jobs WHERE type='search' AND ref_id=? AND status='pending'",[$id]);flash('Search stopped.');}
  elseif($a==='more'){DB::update('campaigns',['status'=>'searching','limit_n'=>$c['limit_n']+max(1,(int)$_POST['n']),'error'=>null],'id=?',[$id]);Queue::push('search',$id);flash('Fetching more listings…');}
  else flash(e(Outreach::bulk($a,array_map('intval',$_POST['ids']??[]))));
  redirect(url('campaign',['id'=>$id]));}
$f=$_GET['f']??'';
$w='campaign_id=?';$args=[$id];
$fm=['noweb'=>"website=''",'web'=>"website<>''",'new'=>"stage='new'",'ready'=>"stage='report_ready'",'contacted'=>"(email_status IN ('sent','opened') OR wa_status IN ('sent','delivered','read','manual'))",'replied'=>'replied_at IS NOT NULL','failed'=>"stage='failed'",'working'=>"stage IN ('queued','auditing','reporting','contacting')"];
if(isset($fm[$f]))$w.=' AND '.$fm[$f];
if(isset($_GET['export']))Outreach::csv(DB::all("SELECT * FROM leads WHERE $w ORDER BY position",$args));
$total=(int)DB::val("SELECT COUNT(*) FROM leads WHERE $w",$args);$per=50;$pg=max(1,(int)($_GET['p']??1));$pages=max(1,(int)ceil($total/$per));
$leads=DB::all("SELECT * FROM leads WHERE $w ORDER BY position LIMIT $per OFFSET ".(($pg-1)*$per),$args);
$cnt=DB::row("SELECT COUNT(*) t,SUM(website='') nw,SUM(stage='new') nn,SUM(stage='report_ready') rr,SUM(email_status IN ('sent','opened') OR wa_status IN ('sent','delivered','read','manual')) ct,SUM(replied_at IS NOT NULL) rp,SUM(stage='failed') fl,SUM(stage IN ('queued','auditing','reporting','contacting')) wk FROM leads WHERE campaign_id=?",[$id]);
$live=$c['status']==='searching'||(int)$cnt['wk']>0;
if(isset($_GET['partial'])){$selectable=true;require APP.'/views/leads_table.php';exit;}
$title=$c['keyword'].($c['location']?' · '.$c['location']:'');$subtitle='Map search results';
$tabs=[''=>['All',$cnt['t']],'new'=>['New',$cnt['nn']],'web'=>['With website',$cnt['t']-$cnt['nw']],'noweb'=>['No website',$cnt['nw']],'ready'=>['Report ready',$cnt['rr']],'contacted'=>['Contacted',$cnt['ct']],'replied'=>['Replied',$cnt['rp']],'failed'=>['Failed',$cnt['fl']],'working'=>['Processing',$cnt['wk']]];
$pc=min(100,round($c['found']/max(1,$c['limit_n'])*100));
?>
<a class="back" href="<?=url('scraper')?>">← All searches</a>
<div class="card mt"><div class="lh"><span class="avc c-orange"><?=icon('map')?></span><div class="grow"><h2><?=e($c['keyword'])?></h2><div class="meta"><span><?=icon('pin')?><?=e($c['location']?:'Any location')?></span><span><?=icon('db')?><?=e(strtoupper($c['source']))?></span><span><?=icon('clock')?>created <?=ago($c['created_at'])?></span><?=cbadge($c['status'])?></div></div><div class="pha"><?php if($c['status']==='searching'):?><form method="post"><?=csrf_field()?><input type="hidden" name="a" value="stop"><button class="btn btn-r"><?=icon('stop')?>Stop search</button></form><?php else:?><form method="post" class="inl"><?=csrf_field()?><input type="hidden" name="a" value="more"><input type="number" name="n" value="20" min="1" max="200" class="sm-in" title="How many more"><button class="btn"><?=icon('plus')?>Fetch more</button></form><?php endif?><a class="btn" href="<?=url('campaign',['id'=>$id,'export'=>1,'f'=>$f])?>"><?=icon('download')?>CSV</a><?php if($cnt['nn']):?><button class="btn btn-p" onclick="A.pick('new');A.modal('m-init')"><?=icon('zap')?>Process all new (<?=(int)$cnt['nn']?>)</button><?php endif?></div></div><div class="row mt"><div class="grow"><div class="pb g"><i style="width:<?=$pc?>%"></i></div></div><small class="muted nowrap"><?=(int)$c['found']?> of <?=$c['limit_n']?> listings</small></div><?php if((int)$c['skipped']):?><div class="al al-info mt mb0"><?=icon('check')?><span><b><?=(int)$c['skipped']?> business<?=$c['skipped']>1?'es were':' was'?> already in your data</b> (earlier searches or Leads) and skipped – no duplicates and no API cost for them.<?=$c['status']==='ready'&&$c['found']<$c['limit_n']?' Google has no more new results for this search – try a nearby area or another keyword.':''?></span></div><?php endif?><?php if($c['error']):?><div class="al al-err mt mb0"><?=icon('alert')?><span><?=e($c['error'])?></span></div><?php endif?></div>
<div class="stats"><?=kpi('Found',(int)$cnt['t'],'pin','sky',(int)($cnt['t']-$cnt['nw']).' with website')?><?=kpi('Reports ready',(int)$cnt['rr'],'file','violet','ready to send')?><?=kpi('Contacted',(int)$cnt['ct'],'send','amber',(int)$cnt['rp'].' replied')?><?=kpi('Processing',(int)$cnt['wk'],'zap','orange',(int)$cnt['fl'].' failed')?></div>
<div class="tabs"><?php foreach($tabs as $k=>[$l,$n]):if($k==='working'&&!$n&&$f!=='working')continue;?><a href="<?=url('campaign',['id'=>$id,'f'=>$k])?>" class="<?=$f===$k?'on':''?>"><?=e($l)?><em><?=(int)$n?></em></a><?php endforeach?></div>
<div class="card p0"><?php $campaignId=$id;require APP.'/views/bulkbar.php';?><div id="live" <?=$live?'data-live="'.e(url('campaign',['id'=>$id,'f'=>$f,'partial'=>1,'p'=>$pg])).'"':''?>><?php $selectable=true;$emptyMsg=$c['status']==='searching'?'Searching Google Maps… listings will appear here in a few seconds.':'Nothing matches this filter.';require APP.'/views/leads_table.php';?></div><?php if($pages>1):?><div class="pg"><span class="muted"><?=number_format($total)?> businesses · page <?=$pg?> of <?=$pages?></span><?php for($i=max(1,$pg-3);$i<=min($pages,$pg+3);$i++):?><a href="<?=url('campaign',['id'=>$id,'f'=>$f,'p'=>$i])?>" class="<?=$i===$pg?'on':''?>"><?=$i?></a><?php endfor?></div><?php endif?></div>
