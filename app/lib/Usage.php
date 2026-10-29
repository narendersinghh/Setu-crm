<?php
// Counts every external API call, estimates cost and compares with free limits
final class Usage{
  // unit: what one "unit" is. price: USD per unit (tokens: per 1M input/output). free: free units per month (or per day for 'day')
  static function catalog(){return [
    'google_places'=>['Google Places – Text Search (Enterprise)','search','requests',0.035,1000,'month','https://developers.google.com/maps/billing-and-pricing/pricing','1 request = 1 result page of up to 20 businesses (20 dentists = 1 request, 60 = 3). Billed per request – not per business or per field – at the highest SKU of the fields asked for. Phone, website, rating & hours make it "Text Search Enterprise": $35 per 1,000 after 1,000 free requests a month.'],
    'google_places_atmo'=>['Google Places – Text Search (Enterprise + Atmosphere)','search','requests',0.040,1000,'month','https://developers.google.com/maps/billing-and-pricing/pricing','Used only when "Fetch business descriptions" is on (adds the editorial summary): $40 per 1,000 after 1,000 free requests a month.'],
    'google_places_ids'=>['Google Places – duplicate check (IDs only)','search','requests',0,0,'month','https://developers.google.com/maps/billing-and-pricing/pricing','Text Search asking only for place IDs is free and unlimited (Essentials IDs only). Setu uses it before every page so businesses already in your data are never paid for twice.'],
    'google_places_details'=>['Google Places – Place Details (Enterprise)','search','requests',0.020,1000,'month','https://developers.google.com/maps/billing-and-pricing/pricing','1 request = full details of 1 business. Used instead of a whole result page when only 1 new business is left on a page (cheaper). $20 per 1,000 after 1,000 free a month.'],
    'google_places_details_atmo'=>['Google Places – Place Details (Enterprise + Atmosphere)','search','requests',0.025,1000,'month','https://developers.google.com/maps/billing-and-pricing/pricing','Place Details with business descriptions: $25 per 1,000 after 1,000 free a month.'],
    'serpapi'=>['SerpAPI – Google Maps','search','searches',0.015,250,'month','https://serpapi.com/pricing','Each page of 20 results = 1 search. Free plan: 250 searches/month; paid plans from about $0.015 per search.'],
    'pagespeed'=>['Google PageSpeed Insights','audit','calls',0,25000,'day','https://developers.google.com/speed/docs/insights/v5/get-started','Free. Default quota 25,000 calls per day.'],
    'anthropic'=>['Anthropic Claude','tokens','tokens',0,0,'month','https://www.anthropic.com/pricing','Billed per million input / output tokens (prices below). One report ≈ 10k input + 5k output tokens.'],
    'web_search'=>['Claude web research','search','searches',0.01,0,'month','https://docs.anthropic.com/en/docs/agents-and-tools/tool-use/web-search-tool','Optional live research while writing reports: $10 per 1,000 searches (about 2–4 per report).'],
    'openai'=>['OpenAI','tokens','tokens',0,0,'month','https://openai.com/api/pricing',''],
    'openrouter'=>['OpenRouter','tokens','tokens',0,0,'month','https://openrouter.ai/models',''],
    'gemini'=>['Google Gemini','tokens','tokens',0,0,'month','https://ai.google.dev/pricing','Free tier with rate limits is available.'],
    'groq'=>['Groq','tokens','tokens',0,0,'month','https://groq.com/pricing',''],
    'deepseek'=>['DeepSeek','tokens','tokens',0,0,'month','https://api-docs.deepseek.com/quick_start/pricing',''],
    'custom'=>['Custom AI','tokens','tokens',0,0,'month','',''],
    'whatsapp'=>['WhatsApp Cloud API','message','messages',0.0107,0,'month','https://developers.facebook.com/docs/whatsapp/pricing','Business-initiated (marketing/utility template) messages are charged per message; India marketing ≈ ₹0.88. Replies within 24h of a customer message are free.'],
    'whatsapp_api'=>['WhatsApp (other provider)','message','messages',0,0,'month','','Check your provider\'s plan.'],
    'voice_sarvam'=>['Sarvam AI voice','minute','minutes',0.06,0,'month','https://www.sarvam.ai/pricing','Charged per call minute – set your plan\'s rate.'],
    'voice_bland'=>['Bland AI voice','minute','minutes',0.09,0,'month','https://www.bland.ai/pricing',''],
    'voice_vapi'=>['Vapi voice','minute','minutes',0.05,0,'month','https://vapi.ai/pricing','Platform fee; model, voice and telephony are billed on top.'],
    'voice_retell'=>['Retell AI voice','minute','minutes',0.07,0,'month','https://www.retellai.com/pricing',''],
    'voice_custom'=>['Other voice API','minute','minutes',0,0,'month','',''],
    'email'=>['Email sending','email','emails',0,0,'month','','Free with your hosting mailbox; hosts usually limit to a few hundred emails per hour.'],
    'gcal'=>['Google Calendar API','call','calls',0,1000000,'day','https://developers.google.com/calendar/api/guides/quota','Free (generous daily quota).']];}
  // Model prices in USD per 1M tokens [input, output]; editable in Usage → Prices
  static function modelPrices(){return ['claude-opus-5-5'=>[4,20],'claude-opus-5'=>[5,25],'claude-sonnet-5-5'=>[2,10],'claude-sonnet-5'=>[2,10],'claude-haiku-5-5'=>[0.10,0.50],'claude-fable-5-1'=>[10,50],'gpt-4.1-mini'=>[0.40,1.60],'gpt-4.1'=>[2,8],'gpt-4o'=>[2.50,10],'gemini-2.5-flash'=>[0.30,2.50],'gemini-2.5-pro'=>[1.25,10],'deepseek-chat'=>[0.27,1.10],'llama-3.3-70b-versatile'=>[0.59,0.79]];}
  static function prices(){
    $o=jd(setting('usage_prices'));$c=self::catalog();
    foreach($c as $k=>$x)$o[$k]=($o[$k]??[])+['price'=>$x[3],'free'=>$x[4]];
    $o['_models']=($o['_models']??[])+array_map(fn($p)=>['in'=>$p[0],'out'=>$p[1]],self::modelPrices());
    return $o;}
  static function log($service,$op='',$calls=1,$in=0,$out=0,$model=null,$err=false){
    try{
      $p=self::prices();$cost=0;
      $u=self::catalog()[$service][2]??'calls';if(($p[$service]??null)!==null&&$u!=='tokens')$cost=($u==='minutes'?$in/60:($in?:$calls))*(float)$p[$service]['price'];
      if($model!==null){$mp=$p['_models'][$model]??$p['_models'][preg_replace('~^.*/~','',$model)]??null;if($mp)$cost=$in/1e6*(float)$mp['in']+$out/1e6*(float)$mp['out'];}
      DB::q('INSERT INTO api_usage (day,service,op,calls,units_in,units_out,cost,errors) VALUES (CURDATE(),?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE calls=calls+VALUES(calls),units_in=units_in+VALUES(units_in),units_out=units_out+VALUES(units_out),cost=cost+VALUES(cost),errors=errors+VALUES(errors)',[$service,mb_substr((string)$op,0,40),$calls,(int)$in,(int)$out,round($cost,6),$err?1:0]);return $cost;}
    catch(Throwable $e){return 0;}}
  // Month-to-date summary per service with projection to month end
  static function month(){
    $rows=DB::all("SELECT service,SUM(calls) calls,SUM(units_in) uin,SUM(units_out) uout,SUM(cost) cost,SUM(errors) errors FROM api_usage WHERE day>=DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY service");
    $today=[];foreach(DB::all('SELECT service,SUM(calls) calls FROM api_usage WHERE day=CURDATE() GROUP BY service') as $r)$today[$r['service']]=(int)$r['calls'];
    $d=(int)date('j');$dim=(int)date('t');$out=[];$p=self::prices();
    foreach($rows as $r){
      $c=self::catalog()[$r['service']]??[$r['service'],'','calls',0,0,'month','',''];
      $units=$c[2]==='minutes'?round($r['uin']/60,1):($c[2]==='tokens'?$r['uin']+$r['uout']:(int)$r['calls']);
      $free=(float)($p[$r['service']]['free']??0);$per=$c[5];
      $used=$per==='day'?($today[$r['service']]??0):$units;
      $proj=$per==='day'?$used:($d?round($units/$d*$dim):$units);
      $out[$r['service']]=['name'=>$c[0],'unit'=>$c[2],'calls'=>(int)$r['calls'],'units'=>$units,'in'=>(int)$r['uin'],'out'=>(int)$r['uout'],'cost'=>(float)$r['cost'],'proj_cost'=>$d?round($r['cost']/$d*$dim,2):0,'free'=>$free,'per'=>$per,'used'=>$used,'proj'=>$proj,'errors'=>(int)$r['errors'],'pct'=>$free>0?min(999,round($used/$free*100)):null,'price'=>$p[$r['service']]['price']??0,'url'=>$c[6],'note'=>$c[7]];}
    return $out;}
  static function daily($days=30){
    $o=[];foreach(DB::all('SELECT day,SUM(calls) calls,SUM(cost) cost FROM api_usage WHERE day>=CURDATE()-INTERVAL ? DAY GROUP BY day ORDER BY day',[$days-1]) as $r)$o[$r['day']]=$r;
    return $o;}
  // Is this API set up / in use? [label, colour]
  static function status($k){
    $has=fn($x)=>setting($x)!=='';$ai=setting('ai_provider','anthropic');
    switch($k){
      case 'serpapi':return $has('serpapi_key')?(setting('maps_source')==='serpapi'?['Active','green']:['Key added','teal']):['Not added','gray'];
      case 'pagespeed':return $has('google_api_key')&&setting('pagespeed')==='1'?['Active','green']:['Off','gray'];
      case 'web_search':return $ai==='anthropic'&&$has('ai_key_anthropic')&&setting('ai_web_research','1')==='1'?['Active','green']:['Off','gray'];
      case 'whatsapp':return setting('wa_provider')==='cloud'?['Active','green']:['Not added','gray'];
      case 'whatsapp_api':return setting('wa_provider')==='generic'?['Active','green']:['Not added','gray'];
      case 'email':return Mailer::ready()?['Active · '.(Mailer::smtp()?'SMTP':'PHP mail'),'green']:['Not set up','amber'];
      case 'gcal':return GCal::ready()?['Connected','green']:['Not connected','gray'];}
    if(strpos($k,'google_places')===0)return !$has('google_api_key')?['Not added','gray']:($k==='google_places_ids'?['Free · automatic','green']:(strpos($k,'_atmo')!==false?(Maps::atmo()?['Active','green']:['Off','gray']):(Maps::atmo()?['Off','gray']:(setting('maps_source','places')==='places'?['Active','green']:['Key added','teal']))));
    if(strpos($k,'voice_')===0)return setting('voice_provider')===substr($k,6)?(Voice::ready()?['Active','green']:['Incomplete','amber']):['Not added','gray'];
    if(isset(AI::providers()[$k]))return $has('ai_key_'.$k)?($ai===$k?['Active','green']:['Key added','teal']):['Not added','gray'];
    return ['—','gray'];}
  // USD → local currency, refreshed once a day from a free rates API (falls back to the last known or manual rate)
  static function fx(){
    $cur=strtoupper(setting('currency','INR'));if($cur==='USD')return [1.0,'—',''];
    if(setting('fx_mode','auto')==='manual')return [(float)setting('usd_rate','84'),'manual',''];
    $c=jd(setting('fx_cache'));
    if(($c['cur']??'')!==$cur||($c['day']??'')!==date('Y-m-d')){
      $r=http_json('https://open.er-api.com/v6/latest/USD',['timeout'=>8]);
      $v=$r['data']['rates'][$cur]??null;
      if($v){$c=['cur'=>$cur,'day'=>date('Y-m-d'),'rate'=>(float)$v,'at'=>date('Y-m-d H:i')];Settings::set('fx_cache',je($c));}
      elseif(($c['cur']??'')!==$cur)return [(float)setting('usd_rate','84'),'fallback',''];}
    return [(float)$c['rate'],'auto',$c['at']??''];}
  // Estimated bill this month after free tiers: [cost, projected cost]
  static function bill($k,$m,$price,$free){
    $c=self::catalog()[$k]??null;if(!$c||$c[2]==='tokens')return [(float)($m['cost']??0),(float)($m['proj_cost']??0)];
    if($c[5]==='day'){$o=0;foreach(DB::all("SELECT SUM(calls) n FROM api_usage WHERE service=? AND day>=DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY day",[$k]) as $r)$o+=max(0,$r['n']-$free)*$price;$d=max(1,(int)date('j'));return [$o,$o/$d*(int)date('t')];}
    $u=(float)($m['units']??0);$pj=(float)($m['proj']??0);
    return [max(0,$u-$free)*$price,max(0,$pj-$free)*$price];}}
