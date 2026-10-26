<?php
$title='AI Connection';
$P=AI::providers();
if(is_post()){
  $p=$_POST['p']??'';
  if($p==='research'){Settings::set('ai_web_research',empty($_POST['on'])?'0':'1');flash('Web research '.(empty($_POST['on'])?'off':'on').'.');redirect(url('ai'));}
  if(isset($P[$p])){
    if(($_POST['key_'.$p]??'')!=='')Settings::set('ai_key_'.$p,trim($_POST['key_'.$p]));
    if(!empty($_POST['clear_'.$p]))Settings::set('ai_key_'.$p,'');
    if(isset($_POST['model_'.$p]))Settings::set('ai_model_'.$p,trim($_POST['model_'.$p]));
    if($p==='custom'&&isset($_POST['url']))Settings::set('ai_custom_url',trim($_POST['url']));
    Settings::set('ai_web_research',empty($_POST['research'])?'0':'1');
    Settings::set('ai_provider',$p);
    if(($_POST['a']??'')==='test'){
      $t0=microtime(true);
      try{$r=AI::complete('You are a helpful assistant.','Reply with exactly: Connection OK',200,$p);flash('<b>'.e($P[$p]['name']).'</b> replied in '.round(microtime(true)-$t0,1).'s: “'.e(mb_strimwidth(trim($r),0,120,'…')).'”');}
      catch(Throwable $e){flash(e($e->getMessage()),'err');}}
    else flash(e($P[$p]['name']).' saved and in use.');}
  redirect(url('ai'));}
$act=setting('ai_provider','anthropic');
$sug=['anthropic'=>['claude-opus-5-5','claude-sonnet-5-5','claude-haiku-5-5'],'openai'=>['gpt-4.1','gpt-4.1-mini','gpt-4o'],'openrouter'=>['openrouter/auto','anthropic/claude-sonnet-5-5','openai/gpt-4.1'],'gemini'=>['gemini-2.5-pro','gemini-2.5-flash'],'groq'=>['llama-3.3-70b-versatile'],'deepseek'=>['deepseek-chat'],'custom'=>[]];
?>
<div class="g3r"><form method="post" class="card"><?=csrf_field()?><div class="card-h"><h3><span class="hi c-violet"><?=icon('spark')?></span>Connect your AI</h3><?php if(setting('ai_key_'.$act)):?><span class="bdg c-green bdg-dot">Using <?=e($P[$act]['name'])?></span><?php endif?></div>
<label>1. Choose a provider<select name="p" data-toggle><?php foreach($P as $k=>$p):?><option value="<?=$k?>" <?=$act===$k?'selected':''?>><?=e($p['name'])?><?=setting('ai_key_'.$k)!==''?' ✓':''?></option><?php endforeach?></select></label>
<?php foreach($P as $k=>$p):$has=setting('ai_key_'.$k)!=='';?><div data-show="p=<?=$k?>"><p class="sm muted mt0"><?=e($p['note'])?><?php if($p['url']):?> <a class="lnk" href="<?=e($p['url'])?>" target="_blank" rel="noopener">Get an API key ↗</a><?php endif?></p><?php if($k==='custom'):?><label>Base URL<input name="url" value="<?=e(setting('ai_custom_url'))?>" placeholder="https://api.example.com/v1"></label><?php endif?><div class="fg"><label>2. API key<input type="password" name="key_<?=$k?>" autocomplete="new-password" placeholder="<?=$has?'•••••••• saved – type to replace':'Paste your API key'?>"><?php if($has):?><span class="chk sm" style="margin-top:6px"><input type="checkbox" name="clear_<?=$k?>" value="1"> remove saved key</span><?php endif?></label><label>3. Model<input name="model_<?=$k?>" value="<?=e(setting('ai_model_'.$k)?:($sug[$k][0]??''))?>" list="m-<?=$k?>"><datalist id="m-<?=$k?>"><?php foreach($sug[$k] as $m):?><option value="<?=e($m)?>"><?php endforeach?></datalist><small>Suggested: <?=e(implode(', ',$sug[$k])?:'any model your endpoint supports')?></small></label></div><details class="mb0"><summary class="lnk sm" style="cursor:pointer">Step-by-step: how to get a key</summary><ol class="ol"><?php foreach($p['steps'] as $st):?><li><?=e($st)?></li><?php endforeach?></ol></details></div><?php endforeach?>
<label class="chk mt"><span class="sw"><input type="checkbox" name="research" value="1" <?=setting('ai_web_research','1')==='1'?'checked':''?>><i></i></span> Let the AI research the web while writing reports (Claude only – recommended)</label>
<div class="card-f"><button class="btn btn-p" name="a" value="save"><?=icon('check')?>Save &amp; use</button><button class="btn" name="a" value="test"><?=icon('zap')?>Save &amp; test</button></div></form>
<div class="col"><div class="card"><div class="card-h"><h3><span class="hi c-blue"><?=icon('activity')?></span>Connected providers</h3></div><?php foreach($P as $k=>$p):$has=setting('ai_key_'.$k)!=='';if(!$has&&$k!==$act)continue;?><div class="li"><span class="avc c-<?=$act===$k?'green':'gray'?>"><?=e(mb_substr($p['name'],0,1))?></span><div class="grow"><b><?=e($p['name'])?></b><small><?=e(setting('ai_model_'.$k)?:'no model set')?></small></div><?=$act===$k?'<span class="bdg c-green">In use</span>':'<span class="bdg c-teal">Key saved</span>'?></div><?php endforeach?></div>
<div class="card"><div class="card-h"><h3><span class="hi c-amber"><?=icon('book')?></span>How AI is used</h3></div><ol class="ol mt0"><li>Setu audits the website and Google listing (no AI cost).</li><li>The AI researches the market and writes the report from the audit data and your prompt.</li><li>Setu designs the branded PDF.</li></ol><p class="hint">One report uses roughly 15–30k tokens. See costs in <a class="lnk" href="<?=url('usage')?>">API usage</a>.</p></div></div></div>
