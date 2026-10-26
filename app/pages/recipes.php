<?php
$title='Automation recipes';$subtitle='Ready-made flows – add one, review its steps, then switch it on';
$AC=Automation::actions();$T=Automation::triggers();
$rc=['g'=>'green','b'=>'blue','p'=>'violet','o'=>'orange','y'=>'amber','t'=>'teal','r'=>'rose','n'=>'indigo'];
$used=array_column(DB::all('SELECT name FROM automations'),'name');
$unit=fn($s)=>(int)($s['n']??1).' '.((int)($s['n']??1)===1?rtrim($s['u']??'h','s'):($s['u']??'h'));
?>
<a class="back" href="<?=url('automations')?>">← Automations</a>
<div class="rcg mt"><?php foreach(Automation::templates() as $k=>$t):?><form method="post" action="<?=url('automations')?>" class="card rcp3 c-<?=$rc[$t['c']]??'violet'?>"><?=csrf_field()?><input type="hidden" name="a" value="template"><input type="hidden" name="t" value="<?=$k?>"><div class="rcp3-h"><span class="hi"><?=icon($t['i'])?></span><div class="grow"><b><?=e($t['name'])?></b><small><?=icon('zap')?>When <?=e(mb_strtolower($T[$t['trig']][0]??$t['trig']))?></small></div><?php if(in_array($t['name'],$used,true)):?><span class="bdg c-green sm">Added</span><?php endif?></div><p><?=e($t['desc'])?></p><div class="au-flow"><?php foreach($t['steps'] as $i=>$s):$x=$AC[$s['t']];?><?=$i?'<em class="arr">→</em>':''?><span><?=icon($x[1])?><?=e($s['t']==='wait'?'Wait '.$unit($s):$x[0])?></span><?php endforeach?></div><div class="rcp3-f"><button class="btn btn-sm btn-p"><?=icon('plus')?>Use this recipe</button></div></form><?php endforeach?></div>
