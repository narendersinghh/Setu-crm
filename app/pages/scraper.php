<?php
$title='Map Scraper';$subtitle='Find businesses on Google Maps – results go to Scraped Data, not your Leads';
if(is_post()){
  $a=$_POST['a']??'';
  if($a==='create'){
    $kw=trim($_POST['keyword']??'');$loc=trim($_POST['location']??'');
    $n=(int)($_POST['limit']??20);$n=max(1,min(500,$n?:20));
    $src=in_array($_POST['source']??'',['places','serpapi'],true)?$_POST['source']:setting('maps_source','places');
    if($kw===''){flash('Please enter a keyword.','err');redirect(url('scraper',['new'=>1]));}
    if($src==='places'&&$n>60)flash('Google Places API returns at most 60 results per search – use SerpAPI for more. Fetching up to 60.','warn');
    $id=DB::insert('campaigns',['keyword'=>mb_substr($kw,0,180),'location'=>mb_substr($loc,0,180),'limit_n'=>$src==='places'?min($n,60):$n,'source'=>$src]);
    Queue::push('search',$id);
    redirect(url('campaign',['id'=>$id]));}
  if($a==='delete'){
    $id=(int)$_POST['id'];
    $ids=array_column(DB::all('SELECT id FROM leads WHERE campaign_id=? AND is_lead=0',[$id]),'id');
    if($ids)Outreach::bulk('delete',$ids);
    DB::q('UPDATE leads SET campaign_id=0 WHERE campaign_id=?',[$id]);
    DB::q("DELETE FROM jobs WHERE type='search' AND ref_id=?",[$id]);
    DB::q('DELETE FROM campaigns WHERE id=?',[$id]);
    flash('Search deleted. Businesses already moved to Leads were kept.');redirect(url('scraper'));}}
$rows=DB::all("SELECT c.*,COUNT(l.id) leads,SUM(l.website<>'') web,SUM(l.stage IN ('report_ready','contacted','opened','replied','won','lost')) rr,SUM(l.email_status IN ('sent','opened') OR l.wa_status IN ('sent','delivered','read','manual')) ct,SUM(l.replied_at IS NOT NULL) rp,SUM(l.is_lead) il FROM campaigns c LEFT JOIN leads l ON l.campaign_id=c.id GROUP BY c.id ORDER BY c.id DESC LIMIT 200");
$src=setting('maps_source','places');
$keyOk=$src==='serpapi'?setting('serpapi_key')!=='':setting('google_api_key')!=='';
$t=['f'=>0,'w'=>0,'r'=>0,'c'=>0,'p'=>0];foreach($rows as $x){$t['f']+=$x['leads'];$t['w']+=$x['web'];$t['r']+=$x['rr'];$t['c']+=$x['ct'];$t['p']+=$x['rp'];}
?>
<div class="card hero"><div class="hero-t"><div><span class="eyebrow"><?=icon('map')?>Google Maps prospecting</span><h2>Find businesses to help</h2><p class="muted">Search any category and area. We collect website, phone, rating and reviews, then find missing phone numbers and emails on each website.</p></div><?php if(!$keyOk):?><a class="btn" href="<?=url('settings',['tab'=>'data'])?>"><?=icon('alert')?>Add an API key first</a><?php endif?></div>
<form method="post" class="sf"><?=csrf_field()?><input type="hidden" name="a" value="create"><label class="kw">What are you looking for?<input name="keyword" placeholder="e.g. dentist, interior designer, CA firm" required <?=isset($_GET['new'])?'autofocus':''?>></label><label>Where?<input name="location" placeholder="e.g. Baner, Pune"></label><label>How many?<input name="limit" type="number" min="1" max="500" value="20" list="lim" required><datalist id="lim"><option value="10"><option value="20"><option value="30"><option value="50"><option value="100"><option value="200"></datalist></label><label>Data source<select name="source"><option value="places" <?=$src==='places'?'selected':''?>>Google Places (≤60)</option><option value="serpapi" <?=$src==='serpapi'?'selected':''?>>SerpAPI (100+)</option></select></label><button class="btn btn-lg sbtn"><?=icon('search')?>Search</button></form></div>
<div class="stats"><?=kpi('Searches',count($rows),'search','orange','all time')?><?=kpi('Businesses found',number_format($t['f']),'pin','sky',$t['f']?round($t['w']/$t['f']*100).'% have a website':'—',url('prospects'))?><?=kpi('Reports ready',$t['r'],'file','violet','AI audit PDFs',url('reports'))?><?=kpi('Contacted',$t['c'],'send','amber',$t['c']?round($t['p']/$t['c']*100,1).'% replied':'no outreach yet')?></div>
<div class="card p0"><div class="card-h"><h3><span class="hi c-orange"><?=icon('list')?></span>Your searches</h3><span class="bdg c-gray"><?=count($rows)?></span></div><?php if(!$rows):?><div class="empty"><?=icon('map','eic')?><h4>No searches yet</h4><p>Type a category and an area above – results appear here and in Scraped Data within seconds.</p></div><?php else:?><div class="tw"><table class="tbl"><thead><tr><th class="sn">#</th><th>Search</th><th>Progress</th><th class="num">Website</th><th class="num">Reports</th><th class="num">Contacted</th><th class="num">Replied</th><th class="num">In Leads</th><th>Status</th><th></th></tr></thead><tbody><?php $sn=0;foreach($rows as $c):$sn++;$pc=min(100,round($c['leads']/max(1,$c['limit_n'])*100));?><tr><td class="sn"><?=$sn?></td><td class="biz"><a href="<?=url('campaign',['id'=>$c['id']])?>"><b><?=e($c['keyword'])?></b></a><small><?=icon('pin','xs')?> <?=e($c['location']?:'Any location')?> · <?=e(strtoupper($c['source']))?> · <?=ago($c['created_at'])?></small></td><td style="min-width:150px"><div class="pb <?=$c['status']==='error'?'r':'g'?>"><i style="width:<?=$pc?>%"></i></div><small class="pbm"><?=(int)$c['leads']?> of <?=$c['limit_n']?> listings</small></td><td class="num"><?=(int)$c['web']?></td><td class="num"><?=(int)$c['rr']?></td><td class="num"><?=(int)$c['ct']?></td><td class="num"><?=(int)$c['rp']?></td><td class="num"><?=(int)$c['il']?></td><td><?=cbadge($c['status'])?></td><td class="act"><a class="btn btn-sm" href="<?=url('campaign',['id'=>$c['id']])?>">Open</a><form method="post" onsubmit="return confirm('Delete this search and its scraped businesses? (Businesses already in Leads are kept.)')"><?=csrf_field()?><input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="ib red" title="Delete"><?=icon('trash')?></button></form></td></tr><?php endforeach?></tbody></table></div><?php endif?></div>
