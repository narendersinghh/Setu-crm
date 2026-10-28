<?php
final class Maps{
  // Returns ['items'=>[...normalized], 'next'=>token|null]
  static function search($query,$next=null,$source='places'){
    return $source==='serpapi'?self::serp($query,$next):self::places($query,$next);}
  // Google bills Places API (New) per REQUEST at the highest SKU of the fields asked for (not per business, not per field):
  // · Text Search with only places.id = "Essentials (IDs only)" → free & unlimited (used to skip businesses you already have)
  // · one Text Search page (up to 20 businesses) with phone/website/rating/hours = 1 "Text Search Enterprise" request
  // · + editorialSummary (business description) = 1 "Text Search Enterprise + Atmosphere" request (dearer) – off by default
  // · Place Details for ONE business with the same fields = 1 "Place Details Enterprise" request
  const F='id,displayName,formattedAddress,addressComponents,nationalPhoneNumber,internationalPhoneNumber,websiteUri,rating,userRatingCount,googleMapsUri,primaryTypeDisplayName,types,regularOpeningHours.weekdayDescriptions,businessStatus,location,photos.name';
  static function atmo(){return setting('maps_atmosphere','0')==='1';}
  // Usage service key = billing SKU of a full request
  static function sku($details=false){return 'google_places'.($details?'_details':'').(self::atmo()?'_atmo':'');}
  static function fields($prefix='places.'){return implode(',',array_map(fn($f)=>$prefix.$f,explode(',',self::F.(self::atmo()?',editorialSummary':''))));}
  static function key(){$key=setting('google_api_key');if(!$key)throw new Exception('Google Places API key missing. Add it in Settings → Map scraper & audits.');return $key;}
  static function body($q,$next){$b=['textQuery'=>$q,'pageSize'=>20,'languageCode'=>setting('maps_language','en'),'regionCode'=>setting('maps_region','in')];if($next)$b['pageToken']=$next;return $b;}
  static function places($q,$next){
    $r=http_json('https://places.googleapis.com/v1/places:searchText',['json'=>self::body($q,$next),'headers'=>['X-Goog-Api-Key: '.self::key(),'X-Goog-FieldMask: '.self::fields().',nextPageToken'],'timeout'=>40]);
    Usage::log(self::sku(),'text_search',1,0,0,null,$r['code']!==200);
    if($r['code']!==200)throw new Exception(self::gerr($r));
    return ['items'=>array_map([self::class,'item'],$r['data']['places']??[]),'next'=>$r['data']['nextPageToken']??null];}
  // Free check: only the place IDs of one result page (Text Search Essentials IDs only – no charge)
  static function ids($q,$next){
    $r=http_json('https://places.googleapis.com/v1/places:searchText',['json'=>self::body($q,$next),'headers'=>['X-Goog-Api-Key: '.self::key(),'X-Goog-FieldMask: places.id,nextPageToken'],'timeout'=>30]);
    Usage::log('google_places_ids','text_search_ids',1,0,0,null,$r['code']!==200);
    if($r['code']!==200)throw new Exception(self::gerr($r));
    return ['ids'=>array_column($r['data']['places']??[],'id'),'next'=>$r['data']['nextPageToken']??null];}
  // Full data for one business (1 Place Details request)
  static function details($id){
    $r=http_json('https://places.googleapis.com/v1/places/'.rawurlencode($id).'?'.http_build_query(['languageCode'=>setting('maps_language','en'),'regionCode'=>setting('maps_region','in')]),['headers'=>['X-Goog-Api-Key: '.self::key(),'X-Goog-FieldMask: '.self::fields('')],'timeout'=>30]);
    Usage::log(self::sku(true),'place_details',1,0,0,null,$r['code']!==200);
    if($r['code']!==200)throw new Exception(self::gerr($r));
    return self::item($r['data']);}
  static function item($p){
    $city='';
    foreach($p['addressComponents']??[] as $c)if(in_array('locality',$c['types']??[],true)){$city=$c['longText'];break;}
    return ['place_id'=>$p['id'],'name'=>$p['displayName']['text']??'','category'=>$p['primaryTypeDisplayName']['text']??str_replace('_',' ',$p['types'][0]??''),'address'=>$p['formattedAddress']??'','city'=>$city,'phone'=>$p['internationalPhoneNumber']??$p['nationalPhoneNumber']??'','website'=>$p['websiteUri']??'','rating'=>$p['rating']??null,'reviews'=>$p['userRatingCount']??0,'maps_url'=>$p['googleMapsUri']??'','lat'=>$p['location']['latitude']??null,'lng'=>$p['location']['longitude']??null,'raw'=>['photos'=>count($p['photos']??[]),'hours'=>$p['regularOpeningHours']['weekdayDescriptions']??[],'status'=>$p['businessStatus']??'','description'=>$p['editorialSummary']['text']??'','types'=>$p['types']??[]]];}
  // Readable Google error with the exact reason code and how to fix it
  static function gerr($r){
    $e=$r['data']['error']??[];
    $msg='Google Places error '.$r['code'].': '.($e['message']??($r['error']?:substr($r['body'],0,300)));
    $why=[];$links=[];
    foreach($e['details']??[] as $d){
      if(!empty($d['reason']))$why[]=$d['reason'].(!empty($d['metadata']['consumer'])?' ('.$d['metadata']['consumer'].')':'');
      foreach($d['links']??[] as $l)$links[]=$l['url'];}
    if($why)$msg.=' [reason: '.implode(', ',array_unique($why)).']';
    $fix=['SERVICE_DISABLED'=>'Enable "Places API (New)" in the SAME Google Cloud project as this key.','API_KEY_SERVICE_BLOCKED'=>'The key\'s API restrictions block Places API (New) – add it (or set "Don\'t restrict key").','BILLING_DISABLED'=>'Link an active billing account to the project (Billing → Link a billing account).','API_KEY_IP_ADDRESS_BLOCKED'=>'The key is restricted to other IP addresses – set Application restrictions to None or add your server IP.','API_KEY_HTTP_REFERRER_BLOCKED'=>'Website (referrer) restrictions do not work for server calls – set Application restrictions to None.','API_KEY_INVALID'=>'The API key is wrong – copy it again with "Show key" and paste it in Settings → Map scraper & audits.','RATE_LIMIT_EXCEEDED'=>'Quota exceeded – check Quotas for Places API (New).'];
    foreach($why as $w){$c=strtok($w,' ');if(isset($fix[$c]))return $msg.' – Fix: '.$fix[$c].($links?' '.$links[0]:'');}
    if($r['code']===403)$msg.=' – Most common cause when the key restrictions look correct: billing is not enabled or the billing account is closed/unverified for this project (Google Cloud → Billing). Also confirm the key and the enabled API are in the same project, and wait 5 minutes after changes.'.($links?' '.$links[0]:'');
    return $msg;}
  // Step-by-step check used by Settings → Data Source → Test
  static function diagnose($city){
    $key=setting('google_api_key');$out=[];
    if(!$key)return ['✕ No Google API key saved in Settings → Map scraper & audits.'];
    $out[]='Key in use: '.substr($key,0,6).'…'.substr($key,-4).' ('.strlen($key).' chars'.(preg_match('/\s/',$key)?', CONTAINS SPACES – re-paste it':'').')';
    foreach([['Basic search (IDs only – free)','places.id','google_places_ids'],['Full search (name, phone, website, rating… – 1 billable request)','places.id,places.displayName,places.nationalPhoneNumber,places.websiteUri,places.rating','google_places']] as [$label,$mask,$svc]){
      $r=http_json('https://places.googleapis.com/v1/places:searchText',['json'=>['textQuery'=>'cafe in '.$city,'pageSize'=>3],'headers'=>['X-Goog-Api-Key: '.$key,'X-Goog-FieldMask: '.$mask],'timeout'=>30]);Usage::log($svc,'test',1,0,0,null,$r['code']!==200);
      $out[]=($r['code']===200?'✓ ':'✕ ').$label.': '.($r['code']===200?count($r['data']['places']??[]).' results':self::gerr($r));
      if($r['code']!==200)break;}
    return $out;}
  static function serp($q,$next){
    $key=setting('serpapi_key');
    if(!$key)throw new Exception('SerpAPI key missing. Add it in Settings → Map scraper & audits.');
    $start=(int)$next;
    $r=http_json('https://serpapi.com/search.json?'.http_build_query(['engine'=>'google_maps','type'=>'search','q'=>$q,'hl'=>setting('maps_language','en'),'gl'=>setting('maps_region','in'),'start'=>$start,'api_key'=>$key]),['timeout'=>60]);
    Usage::log('serpapi','maps_search',1,0,0,null,$r['code']!==200);
    if($r['code']!==200||!empty($r['data']['error'])){
      if(!empty($r['data']['error'])&&stripos($r['data']['error'],"hasn't returned any results")!==false)return ['items'=>[],'next'=>null];
      throw new Exception('SerpAPI error: '.($r['data']['error']??$r['error']?:$r['code']));}
    $out=[];
    foreach($r['data']['local_results']??[] as $p){
      $addr=$p['address']??'';
      $parts=array_map('trim',explode(',',$addr));
      $out[]=['place_id'=>$p['place_id']??md5(($p['title']??'').$addr),'name'=>$p['title']??'','category'=>$p['type']??'','address'=>$addr,'city'=>count($parts)>=2?preg_replace('/\s*\d{5,6}$/','',$parts[count($parts)-2]):'','phone'=>$p['phone']??'','website'=>$p['website']??'','rating'=>$p['rating']??null,'reviews'=>$p['reviews']??0,'maps_url'=>isset($p['place_id'])?'https://www.google.com/maps/place/?q=place_id:'.$p['place_id']:'','lat'=>$p['gps_coordinates']['latitude']??null,'lng'=>$p['gps_coordinates']['longitude']??null,'raw'=>['photos'=>(int)($p['photos_count']??0),'hours'=>$p['operating_hours']??[],'status'=>!empty($p['permanently_closed'])?'CLOSED_PERMANENTLY':'OPERATIONAL','description'=>$p['description']??'','types'=>$p['types']??[]]];}
    $more=count($out)>=20&&$start<100;
    return ['items'=>$out,'next'=>$more?(string)($start+20):null];}}
