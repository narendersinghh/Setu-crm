<?php
// Public report: report.php?t=TOKEN → online report page (link mode) or PDF; &dl=1 / &pdf=1 → PDF; ?proposal=TOKEN → website proposal PDF
require __DIR__.'/app/bootstrap.php';
$staff=!empty($_SESSION['uid']);
session_write_close();
$t=preg_replace('/[^a-f0-9]/','',$_GET['t']??$_GET['proposal']??'');
$r=$t?DB::row('SELECT * FROM reports WHERE token=?',[$t]):null;
if(!$r){http_response_code(404);exit('Report not found');}
header('X-Robots-Tag: noindex');
if(isset($_GET['proposal'])){
  $f=ROOT.'/'.setting('proposal_pdf');
  if(!setting('proposal_pdf')||!is_file($f)){http_response_code(404);exit('Not found');}
  header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="Website-Proposal.pdf"');header('Content-Length: '.filesize($f));readfile($f);exit;}
$l=DB::row('SELECT name,stage,token,phone FROM leads WHERE id=?',[$r['lead_id']]);
// Count only real external views (not your team, bots or link previews)
$bot=preg_match('~bot|crawl|spider|facebookexternalhit|WhatsApp|preview|curl|wget|python|Headless~i',$_SERVER['HTTP_USER_AGENT']??'');
if(!$staff&&!$bot){
  DB::q('UPDATE reports SET views=views+1 WHERE id=?',[$r['id']]);
  if((int)$r['views']===0){event($r['lead_id'],'viewed','Report opened by the business','web');if(in_array($l['stage']??'',['contacted','report_ready'],true))stage($r['lead_id'],'opened');}}
$mode=report_mode();
$wantPdf=isset($_GET['dl'])||isset($_GET['pdf'])||($mode==='pdf'&&!isset($_GET['web']));
if($wantPdf&&($mode!=='link'||$staff)){
  $f=Report::pdfFile($r);if(!$f){http_response_code(404);exit('Report not available');}
  header('Content-Type: application/pdf');
  header('Content-Disposition: '.(isset($_GET['dl'])?'attachment':'inline').'; filename="Audit-Report-'.preg_replace('/[^a-z0-9]+/i','-',$l['name']??'report').'.pdf"');
  header('Content-Length: '.filesize($f));readfile($f);Cron::kick();exit;}
// Online report: same slides as the PDF, drawn by the visitor's browser and scaled to the screen
$c=Report::ctx($r);if(!$c){http_response_code(404);exit('Report not available');}
$html=Report::modern(...$c);
$cta=setting('report_cta','Book a free strategy call');$book=Booking::link($l);$co=setting('company_name');$ph=preg_replace('/[^\d]/','',setting('company_phone'));
$bar='<div class="wb"><div class="wb-l">'.Report::logoImg('#0F1F45').'<span><b>'.e($co).'</b><small>Growth audit for '.e($l['name']).'</small></span></div><div class="wb-r">'.($mode!=='link'?'<a class="wbb" href="report.php?t='.e($t).'&dl=1">Download PDF</a>':'').($ph?'<a class="wbb" href="https://wa.me/'.(strlen($ph)===10?setting('default_country_code','91'):'').$ph.'?text='.rawurlencode('Hi, I read the growth audit for '.$l['name'].'.').'" target="_blank" rel="noopener">WhatsApp us</a>':'').'<a class="wbb pri" href="'.e($book).'">'.e($cta).'</a></div></div>';
// On screen the report is one continuous page (no page breaks, nothing clipped); printing still gives A4 slides
$css='<style>@media screen{html{background:#E9ECF2}body{padding:84px 0 40px}.rw{width:297mm;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 10px 40px rgba(15,23,42,.14);overflow:hidden;zoom:var(--z,1)}.rw .slide{width:auto;height:auto!important;min-height:0;overflow:visible!important;page-break-after:auto;break-after:auto;padding-bottom:14mm}.rw .slide+.slide{border-top:1px solid #E6EAF2}.rw .slide:not(.cover):not(.close) .sb{flex:none}.rw .cover,.rw .close{min-height:190mm;padding:0!important}.rw .cover{border-radius:0}.rw *{-webkit-line-clamp:unset!important}.rw .pno{display:none}.wb{position:fixed;top:0;left:0;right:0;z-index:9;height:64px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 20px;background:#0F1F45;color:#fff;font-family:Inter,system-ui,sans-serif}.wb-l{display:flex;align-items:center;gap:12px;min-width:0}.wb-l img{height:38px;max-width:120px;object-fit:contain}.wb-l b{display:block;font-size:14px}.wb-l small{display:block;font-size:12px;color:#A9B6D6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wb-r{display:flex;gap:8px;flex:none}.wbb{display:inline-flex;align-items:center;height:38px;padding:0 14px;border-radius:10px;border:1px solid rgba(255,255,255,.25);color:#fff;text-decoration:none;font-size:13.5px;font-weight:600}.wbb.pri{background:#2563EB;border-color:#2563EB}@media(max-width:700px){.wb{height:auto;padding:10px 12px;flex-wrap:wrap}.wbb{height:34px;font-size:12.5px;padding:0 10px}body{padding-top:120px}}}@media print{.wb{display:none}}</style>';
$js='<script>(function(){var r=document.querySelector(".rw");function f(){r.style.setProperty("--z",Math.min(1,(document.documentElement.clientWidth-24)/1123))}f();addEventListener("resize",f)})()</script>';
echo str_replace(['</head>','<body>','</body>'],[$css.'<meta name="viewport" content="width=device-width,initial-scale=1"></head>','<body>'.$bar.'<main class="rw">','</main>'.$js.'</body>'],$html);
Cron::kick();
