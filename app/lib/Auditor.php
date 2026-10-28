<?php
final class Auditor{
  const WEB_CATS=['Technical','Performance','On-page SEO','Content','Conversion','Local SEO','Trust','Social'];
  const MAP_CATS=['Reputation','Visibility','Profile'];
  private static function c(&$a,$label,$st,$detail,$w=1,$cat='On-page SEO'){$a[]=['label'=>$label,'status'=>$st,'detail'=>$detail,'w'=>$w,'cat'=>$cat];}
  private static function score($checks){
    $t=0;$g=0;
    foreach($checks as $c){$t+=$c['w'];$g+=$c['w']*($c['status']==='pass'?1:($c['status']==='warn'?.5:0));}
    return $t?(int)round($g/$t*100):0;}
  static function cats($checks,$order){
    $out=[];
    foreach($order as $c){$x=array_filter($checks,fn($k)=>$k['cat']===$c);if($x)$out[$c]=self::score($x);}
    return $out;}
  static function website($site,$progress=null,$lead=null){
    $P=fn($pct,$m)=>$progress?$progress($pct,$m):null;
    $ch=[];$m=[];
    $P(5,'Opening the website');
    $r=Scraper::fetch($site);
    if($r['error']||$r['code']>=400||$r['code']===0){
      self::c($ch,'Website is reachable','fail','The website could not be opened ('.($r['error']?:'HTTP '.$r['code']).'). Visitors and Google see an error page.',5,'Technical');
      return ['score'=>0,'checks'=>$ch,'meta'=>['url'=>$site,'down'=>true],'cats'=>['Technical'=>0]];}
    $h=$r['body'];$final=$r['url'];
    $host=parse_url($final,PHP_URL_HOST);$root=parse_url($final,PHP_URL_SCHEME).'://'.$host;
    $m['url']=$final;$m['load_time']=round($r['time'],2);$m['ttfb']=round($r['ttfb'],2);$m['size_kb']=round($r['size']/1024);
    $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8"?>'.$h);libxml_clear_errors();
    $x=new DOMXPath($dom);$q=fn($p)=>$x->query($p);
    $meta=function($n)use($x){$e=$x->query("//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='$n']/@content | //meta[@property='$n']/@content");return $e->length?trim($e->item(0)->nodeValue):null;};
    $text=trim(preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('~<(script|style|noscript)[^>]*>.*?</\1>~is',' ',$h)))));
    $lt=strtolower($text);
    $city=strtolower(trim($lead['city']??''));$kw=strtolower(trim($lead['keyword']??$lead['category']??''));
    // ---------- Technical
    $P(15,'Checking security & technical setup');
    $https=stripos($final,'https://')===0;
    self::c($ch,'Secure HTTPS (SSL)',$https?'pass':'fail',$https?'Site loads securely over HTTPS.':'Site is not secure (no HTTPS). Browsers show "Not secure" and Google ranks it lower.',3,'Technical');
    if($https){
      $days=self::sslDays($host);
      if($days!==null){$m['ssl_days']=$days;self::c($ch,'SSL certificate validity',$days>20?'pass':($days>0?'warn':'fail'),$days>0?'Certificate valid for '.$days.' more days.':'Certificate has expired – visitors see a security warning.',1,'Technical');}
      $hr=http('http://'.$host.'/',['timeout'=>10,'max'=>50000,'public'=>true]);
      if($hr['code']){$ok=stripos($hr['url'],'https://')===0;self::c($ch,'HTTP → HTTPS redirect',$ok?'pass':'warn',$ok?'Visitors typing http:// are moved to the secure site.':'The insecure http:// version does not redirect to https:// – duplicate site in Google.',1,'Technical');}}
    if($https&&preg_match('~(src|href)=["\']http://(?!www\.w3\.org)~i',preg_replace('~<a\s[^>]*>~i','',$h)))self::c($ch,'Mixed content','warn','Some images/scripts load over insecure http:// on a secure page – browsers may block them.',1,'Technical');
    $vp=$q("//meta[@name='viewport']")->length>0;
    self::c($ch,'Mobile friendly (viewport)',$vp?'pass':'fail',$vp?'Mobile viewport is set.':'Not mobile-optimised – most local searches happen on phones.',3,'Technical');
    $can=$q("//link[@rel='canonical']")->length>0;
    self::c($ch,'Canonical tag',$can?'pass':'warn',$can?'Canonical URL set.':'No canonical tag – risk of duplicate-content issues.',1,'Technical');
    $lang=$dom->documentElement&&$q('//html[@lang]')->length>0;
    self::c($ch,'Language declared',$lang?'pass':'warn',$lang?'Page language is declared.':'No language attribute – search engines must guess the language.',1,'Technical');
    $fav=$q("//link[contains(@rel,'icon')]")->length>0;
    self::c($ch,'Favicon',$fav?'pass':'warn',$fav?'Favicon present.':'No favicon – looks unprofessional in browser tabs and Google mobile results.',1,'Technical');
    $rb=http($root.'/robots.txt',['timeout'=>10,'max'=>200000,'public'=>true]);$hasRobots=$rb['code']===200&&stripos($rb['type'],'html')===false;
    self::c($ch,'robots.txt',$hasRobots?'pass':'warn',$hasRobots?'robots.txt found.':'No robots.txt file.',1,'Technical');
    $sm=http($root.'/sitemap.xml',['timeout'=>10,'max'=>200000,'public'=>true]);$hasSm=($sm['code']===200&&(stripos($sm['body'],'<urlset')!==false||stripos($sm['body'],'<sitemapindex')!==false))||($hasRobots&&stripos($rb['body'],'sitemap:')!==false);
    self::c($ch,'XML sitemap',$hasSm?'pass':'fail',$hasSm?'XML sitemap found.':'No XML sitemap – Google may miss pages.',2,'Technical');
    if(preg_match('~<meta[^>]+name=["\']robots["\'][^>]+noindex~i',$h))self::c($ch,'Search engine indexing','fail','Page has a "noindex" tag – it is hidden from Google!',5,'Technical');
    // ---------- Performance
    $P(30,'Measuring speed & page weight');
    $sec=$r['time'];
    self::c($ch,'Page load speed',$sec<2.5?'pass':($sec<5?'warn':'fail'),'Homepage HTML loaded in '.round($sec,1).'s (server response '.round($r['ttfb'],2).'s). '.($sec<2.5?'Good.':'Slow pages lose visitors – over half leave after 3 seconds on mobile.'),3,'Performance');
    if($m['size_kb']>800)self::c($ch,'HTML page weight',$m['size_kb']>2000?'fail':'warn','Homepage HTML alone is '.$m['size_kb'].' KB – heavy for mobile data.',1,'Performance');
    $block=0;foreach($q('//head/script[@src]') as $s)if(!$s->hasAttribute('async')&&!$s->hasAttribute('defer')&&$s->getAttribute('type')!=='module')$block++;
    $css=$q("//head/link[@rel='stylesheet']")->length;$m['blocking']=$block+$css;
    self::c($ch,'Render-blocking files',$block+$css<=4?'pass':($block+$css<=10?'warn':'fail'),($block+$css).' scripts/stylesheets must load before anything shows ('.$block.' scripts, '.$css.' CSS files).',2,'Performance');
    $imgs=$q('//img');$srcs=[];$lazy=0;$modern=0;$noalt=0;
    foreach($imgs as $i){$s=$i->getAttribute('src')?:$i->getAttribute('data-src');if($s&&strpos($s,'data:')!==0)$srcs[]=Scraper::abs($s,$final);if(strtolower($i->getAttribute('loading'))==='lazy'||$i->getAttribute('data-src'))$lazy++;if(preg_match('~\.(webp|avif)(\?|$)~i',$s))$modern++;if(trim($i->getAttribute('alt'))==='')$noalt++;}
    $srcs=array_values(array_unique(array_filter($srcs)));$m['images']=count($srcs);
    if($srcs){
      $big=0;$tot=0;$n=0;
      foreach(array_slice($srcs,0,8) as $s){$hd=self::size($s);if($hd>0){$n++;$tot+=$hd;if($hd>300*1024)$big++;}}
      if($n){$m['img_avg_kb']=round($tot/$n/1024);self::c($ch,'Image optimisation',$big===0?'pass':($big<=2?'warn':'fail'),$big?$big.' of '.$n.' sampled images are over 300 KB (average '.$m['img_avg_kb'].' KB) – they slow the site down.':'Sampled images are well compressed (average '.$m['img_avg_kb'].' KB).',2,'Performance');}
      self::c($ch,'Modern image formats (WebP/AVIF)',$modern/max(1,count($srcs))>=.5?'pass':'warn',$modern.' of '.count($srcs).' images use modern formats. WebP is ~30% smaller than JPG/PNG.',1,'Performance');
      if(count($srcs)>5)self::c($ch,'Lazy-loading images',$lazy/count($srcs)>=.4?'pass':'warn',$lazy.' of '.count($srcs).' images load lazily – the rest all load at once.',1,'Performance');}
    if(setting('pagespeed')==='1'){
      $P(38,'Running Google PageSpeed test (mobile)');
      if($ps=self::pagespeed($final)){$m['pagespeed']=$ps;self::c($ch,'Google PageSpeed (mobile)',$ps['score']>=80?'pass':($ps['score']>=50?'warn':'fail'),'Google PageSpeed mobile score '.$ps['score'].'/100 · Largest content shows in '.$ps['lcp'].'s · Layout shift '.$ps['cls'].' · Blocking time '.$ps['tbt'].'ms.',3,'Performance');}}
    // ---------- On-page SEO
    $P(48,'Analysing on-page SEO');
    $t=$q('//title');$title=$t->length?trim(preg_replace('/\s+/',' ',$t->item(0)->textContent)):'';$m['title']=$title;$tl=mb_strlen($title);
    self::c($ch,'SEO title tag',$title===''?'fail':($tl<20||$tl>65?'warn':'pass'),$title===''?'No page title – Google does not know what this page is about.':'"'.mb_substr($title,0,80).'" ('.$tl.' chars). '.($tl<20||$tl>65?'Ideal length is 30-60 characters with main service + city.':'Good length.'),3);
    $d=$meta('description');$m['description']=$d;$dl=mb_strlen((string)$d);
    self::c($ch,'Meta description',!$d?'fail':($dl<70||$dl>170?'warn':'pass'),!$d?'Missing meta description – Google shows random text in search results, lowering clicks.':$dl.' characters. '.($dl<70||$dl>170?'Ideal is 120-160 characters.':'Good.'),2);
    $h1=$q('//h1');$m['h1']=$h1->length?trim($h1->item(0)->textContent):'';
    self::c($ch,'H1 heading',$h1->length===1?'pass':($h1->length===0?'fail':'warn'),$h1->length===0?'No H1 heading – the main topic of the page is unclear to Google.':($h1->length>1?$h1->length.' H1 headings found – use exactly one.':'One H1: "'.mb_substr($m['h1'],0,70).'"'),2);
    $h2=$q('//h2')->length;$h3=$q('//h3')->length;
    self::c($ch,'Heading structure (H2/H3)',$h2>=2?'pass':'warn',$h2.' H2 and '.$h3.' H3 sub-headings found.',1);
    if($city!==''){$inT=strpos(strtolower($title.' '.$m['h1']),$city)!==false;self::c($ch,'City in title / main heading',$inT?'pass':'warn',$inT?'Mentions "'.ucwords($city).'" in the title or H1 – good for local searches.':'"'.ucwords($city).'" is not in the title or H1 – the page is weaker for "near me" and city searches.',2);}
    if($kw!==''){$words=array_filter(explode(' ',preg_replace('/[^a-z0-9 ]/','',$kw)),fn($w)=>strlen($w)>3);$hit=0;foreach($words as $w)if(strpos(strtolower($title.' '.$m['h1'].' '.$d),$w)!==false)$hit++;if($words)self::c($ch,'Main service keyword in title/description',$hit?'pass':'warn',$hit?'The main service ("'.$kw.'") appears in title/heading/description.':'The search phrase "'.$kw.'" does not appear in the title, H1 or description.',2);}
    if($imgs->length)self::c($ch,'Image alt text',$noalt===0?'pass':($noalt/$imgs->length<.3?'warn':'fail'),$noalt.' of '.$imgs->length.' images have no alt text (missed image-search traffic & accessibility).',1);
    $schema=preg_match('~application/ld\+json~i',$h);$lb=preg_match('~"@type"\s*:\s*"(LocalBusiness|Organization|[A-Za-z]*(Store|Business|Clinic|Restaurant|Dentist|Physician|Attorney|Hotel|Agency|School|Service))"~i',$h);
    self::c($ch,'Local business schema markup',$lb?'pass':($schema?'warn':'fail'),$lb?'LocalBusiness/Organization schema found.':($schema?'Some structured data, but no LocalBusiness schema.':'No structured data – Google cannot show hours, rating and address as rich results.'),2,'Local SEO');
    // ---------- Content
    $wc=str_word_count($text);$m['words']=$wc;
    self::c($ch,'Content depth (homepage)',$wc>=600?'pass':($wc>=300?'warn':'fail'),'About '.$wc.' words on the homepage. '.($wc<600?'Thin pages rank poorly – Google prefers 600+ genuinely helpful words.':'Good.'),2,'Content');
    $links=[];foreach($q('//a[@href]') as $a){$u=Scraper::abs($a->getAttribute('href'),$final);if($u&&parse_url($u,PHP_URL_HOST)===$host)$links[strtok($u,'#')]=strtolower(trim($a->textContent));}
    $m['internal_links']=count($links);
    $blog=(bool)array_filter(array_keys($links),fn($u)=>preg_match('~/(blog|news|articles|insights)~i',$u));
    self::c($ch,'Blog / fresh content',$blog?'pass':'warn',$blog?'Has a blog/news section.':'No blog or articles – no fresh content for Google and no answers to customers\' questions.',1,'Content');
    $svc=count(array_filter(array_keys($links),fn($u)=>preg_match('~/(services?|treatments?|products?|solutions)/~i',$u)));
    self::c($ch,'Dedicated service pages',$svc>=3?'pass':($svc?'warn':'fail'),$svc?$svc.' service pages linked.':'No separate service pages – each service needs its own page to rank for it.',2,'Content');
    if(preg_match_all('~(?:©|&copy;|copyright)\s*(?:\d{4}\s*[-–]\s*)?(\d{4})~i',$h,$cm)){$y=max(array_map('intval',$cm[1]));if($y&&$y<(int)date('Y')-1)self::c($ch,'Website freshness','warn','Footer copyright says '.$y.' – the site looks outdated to visitors.',1,'Content');}
    // ---------- Conversion
    $P(60,'Checking how the site turns visitors into enquiries');
    $ga=preg_match('~googletagmanager\.com|google-analytics\.com|gtag\(|G-[A-Z0-9]{6,}~',$h);
    self::c($ch,'Analytics / tracking',$ga?'pass':'fail',$ga?'Google Analytics/Tag Manager installed.':'No Google Analytics – the business cannot measure visitors or enquiries.',2,'Conversion');
    $px=preg_match('~connect\.facebook\.net|fbq\(~',$h);
    self::c($ch,'Ads tracking pixel (Meta)',$px?'pass':'warn',$px?'Meta pixel installed – can retarget visitors.':'No Meta pixel – visitors cannot be retargeted with ads.',1,'Conversion');
    $tel=preg_match('~href=["\']tel:~i',$h);
    self::c($ch,'Click-to-call button',$tel?'pass':'fail',$tel?'Phone number is clickable.':'Phone number is not clickable on mobile – callers have to copy it manually.',2,'Conversion');
    $wa=preg_match('~wa\.me/|api\.whatsapp\.com|whatsapp://~i',$h);
    self::c($ch,'WhatsApp chat button',$wa?'pass':'warn',$wa?'WhatsApp link present.':'No WhatsApp chat button – a big missed channel for enquiries in India.',1,'Conversion');
    $form=$q('//form')->length>0;
    self::c($ch,'Enquiry / contact form',$form?'pass':'warn',$form?'Enquiry form found.':'No enquiry form on the homepage.',1,'Conversion');
    $cta=preg_match('~\b(book (a|an|now|appointment)|get (a )?(free )?quote|call now|enquire now|contact us|schedule|request a call|get started)\b~i',$text);
    self::c($ch,'Clear call-to-action',$cta?'pass':'warn',$cta?'Has clear action buttons (book / call / quote).':'No clear "Book now / Get a quote / Call now" message – visitors don\'t know the next step.',2,'Conversion');
    // ---------- Local SEO
    $map=preg_match('~google\.com/maps|maps\.google|goo\.gl/maps|maps\.app\.goo\.gl~i',$h);
    self::c($ch,'Google Maps embed / link',$map?'pass':'warn',$map?'Website links to Google Maps.':'Website does not embed or link to the Google Maps listing (weak local signal).',1,'Local SEO');
    if($city!=='')self::c($ch,'City / area mentioned on site',substr_count($lt,$city)>=2?'pass':(strpos($lt,$city)!==false?'warn':'fail'),'"'.ucwords($city).'" appears '.substr_count($lt,$city).' time(s) on the homepage.',2,'Local SEO');
    $ph=substr(preg_replace('/\D/','',$lead['phone']??''),-10);
    if(strlen($ph)===10){$digits=preg_replace('/\D/','',$h);self::c($ch,'Phone matches Google listing (NAP)',strpos($digits,$ph)!==false?'pass':'warn',strpos($digits,$ph)!==false?'Same phone number on website and Google – consistent business details.':'The phone number on Google Maps is not shown on the website – inconsistent details reduce local ranking trust.',2,'Local SEO');}
    // ---------- Trust
    $privacy=(bool)array_filter($links,fn($t)=>preg_match('~privacy|terms~',$t));
    self::c($ch,'Privacy policy / terms',$privacy?'pass':'warn',$privacy?'Privacy policy or terms page linked.':'No privacy policy – required for ads and builds trust.',1,'Trust');
    $testi=preg_match('~testimonial|what (our )?(clients|customers|patients) say|reviews?\b|google reviews|★★★~i',$text);
    self::c($ch,'Testimonials / reviews on site',$testi?'pass':'warn',$testi?'Shows testimonials or reviews.':'No customer testimonials on the homepage – social proof is the #1 trust signal.',2,'Trust');
    $about=(bool)array_filter(array_keys($links),fn($u)=>preg_match('~/about~i',$u));
    self::c($ch,'About page',$about?'pass':'warn',$about?'About page found.':'No About page – customers want to know who they are dealing with.',1,'Trust');
    $P(70,'Checking domain age');
    if(($age=self::domainAge($host))!==null){$m['domain_age']=$age;self::c($ch,'Domain age',$age>=2?'pass':'warn','Domain registered about '.$age.' year(s) ago. '.($age<2?'Newer domains need extra effort to earn Google\'s trust.':'An established domain helps rankings.'),1,'Trust');}
    // ---------- Social
    $socials=[];foreach(['facebook','instagram','linkedin','youtube'] as $s)if(stripos($h,$s.'.com/')!==false)$socials[]=$s;
    self::c($ch,'Social media links',count($socials)>=2?'pass':(count($socials)?'warn':'fail'),$socials?'Links to: '.implode(', ',$socials).'.':'No social media profiles linked.',1,'Social');
    $og=$meta('og:title')&&$meta('og:image');
    self::c($ch,'Social sharing preview (Open Graph)',$og?'pass':'warn',$og?'Open Graph tags present.':'Missing Open Graph tags – links shared on WhatsApp/Facebook show no preview image.',1,'Social');
    // ---------- Inner pages & broken links
    if(setting('deep_crawl','1')==='1'&&$links){
      $P(78,'Crawling inner pages & checking for broken links');
      $pick=array_slice(array_keys(array_filter($links,fn($t,$u)=>$u!==$final&&$u!==rtrim($final,'/')&&!preg_match('~\.(pdf|jpe?g|png|zip)$|/cdn-cgi/|wp-login|/feed/?$|#~i',$u),ARRAY_FILTER_USE_BOTH)),0,10);
      $bad=[];$noDesc=0;$titles=[$title];$crawled=0;
      foreach($pick as $i=>$u){
        $pr=http($u,['timeout'=>8,'max'=>150000,'public'=>true]);
        if($pr['code']>=400||$pr['code']===0){$bad[]=$u;continue;}
        if($i<5){$crawled++;if(!preg_match('~<meta[^>]+name=["\']description["\']~i',$pr['body']))$noDesc++;if(preg_match('~<title[^>]*>(.*?)</title>~is',$pr['body'],$tm))$titles[]=trim($tm[1]);}}
      $m['crawled']=$crawled;$m['broken']=count($bad);
      self::c($ch,'Broken internal links',$bad?'fail':'pass',$bad?count($bad).' of '.count($pick).' tested links are broken (e.g. '.mb_strimwidth($bad[0],0,70,'…').') – frustrating for visitors and Google.':'All '.count($pick).' tested internal links work.',2,'Technical');
      if($crawled){self::c($ch,'Inner pages: meta descriptions',$noDesc===0?'pass':'warn',$noDesc.' of '.$crawled.' inner pages are missing a meta description.',1);
        $dup=count($titles)-count(array_unique(array_map('strtolower',$titles)));self::c($ch,'Unique page titles',$dup?'warn':'pass',$dup?$dup.' pages share the same title – Google cannot tell them apart.':'Each crawled page has its own title.',1);}}
    $P(88,'Website audit complete');
    return ['score'=>self::score($ch),'checks'=>$ch,'meta'=>$m,'cats'=>self::cats($ch,self::WEB_CATS)];}
  private static function sslDays($host){
    if(!public_ip('https://'.$host))return null;
    $ctx=stream_context_create(['ssl'=>['capture_peer_cert'=>true,'verify_peer'=>false,'verify_peer_name'=>false,'SNI_enabled'=>true,'peer_name'=>$host]]);
    $c=@stream_socket_client('ssl://'.$host.':443',$en,$es,8,STREAM_CLIENT_CONNECT,$ctx);
    if(!$c)return null;
    $p=stream_context_get_params($c);fclose($c);
    $i=@openssl_x509_parse($p['options']['ssl']['peer_certificate']??'');
    return $i?(int)floor(($i['validTo_time_t']-time())/86400):null;}
  private static function size($u){
    $ch=curl_init($u);curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>6,CURLOPT_USERAGENT=>'Mozilla/5.0']);
    curl_exec($ch);$s=(int)curl_getinfo($ch,CURLINFO_CONTENT_LENGTH_DOWNLOAD);curl_close($ch);return $s;}
  private static function domainAge($host){
    $d=preg_replace('/^www\./','',$host);
    $r=http_json('https://rdap.org/domain/'.rawurlencode($d),['timeout'=>8]);
    foreach($r['data']['events']??[] as $e)if(($e['eventAction']??'')==='registration')return max(0,(int)floor((time()-strtotime($e['eventDate']))/31557600));
    return null;}
  static function pagespeed($u){
    $q=['url'=>$u,'strategy'=>'mobile','category'=>'performance'];
    if($k=setting('google_api_key'))$q['key']=$k;
    $r=http_json('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?'.http_build_query($q),['timeout'=>70]);Usage::log('pagespeed','mobile',1,0,0,null,$r['code']!==200);
    $L=$r['data']['lighthouseResult']??null;if(!$L)return null;
    $a=$L['audits']??[];
    return ['score'=>(int)round(($L['categories']['performance']['score']??0)*100),'lcp'=>round(($a['largest-contentful-paint']['numericValue']??0)/1000,1),'cls'=>round($a['cumulative-layout-shift']['numericValue']??0,2),'tbt'=>(int)($a['total-blocking-time']['numericValue']??0)];}
  static function map($lead,$competitors){
    $ch=[];$raw=jd($lead['raw']);
    $rating=(float)$lead['rating'];$rev=(int)$lead['reviews'];
    $avgRev=$competitors?(int)round(array_sum(array_column($competitors,'reviews'))/count($competitors)):0;
    $avgRat=$competitors?round(array_sum(array_map(fn($c)=>(float)$c['rating'],$competitors))/count($competitors),1):0;
    self::c($ch,'Google rating',$rating>=4.5?'pass':($rating>=4?'warn':'fail'),$rating?$rating.' ★ average rating'.($avgRat?' (top competitors: '.$avgRat.' ★)':'').'. '.($rating<4.5?'Customers filter by rating – 4.5★+ wins most clicks.':'Excellent.'):'No rating yet.',3,'Reputation');
    self::c($ch,'Number of reviews',$rev>=max(100,$avgRev)?'pass':($rev>=30?'warn':'fail'),$rev.' reviews'.($avgRev?' vs. top competitors\' average of '.$avgRev.($avgRev>$rev?' – a gap of '.($avgRev-$rev).' reviews':''):'').'. Review count is one of the strongest Google Maps ranking factors.',3,'Reputation');
    $pos=(int)$lead['position'];
    if($pos)self::c($ch,'Google Maps ranking position',$pos<=3?'pass':($pos<=10?'warn':'fail'),'Ranked #'.$pos.' for "'.($lead['keyword']??'').'". '.($pos>3?'The top 3 (local pack) receive most calls and direction requests.':'In the top 3 – great.'),3,'Visibility');
    self::c($ch,'Website linked on profile',$lead['website']?'pass':'fail',$lead['website']?'Website is linked.':'No website on the Google profile – customers can\'t learn more and Google trusts the listing less.',3,'Profile');
    self::c($ch,'Phone number on profile',$lead['phone_source']==='maps'?'pass':'fail',$lead['phone_source']==='maps'?'Phone number listed.':'No phone number on the Google listing – direct calls are lost.',3,'Profile');
    $ph=(int)($raw['photos']??0);
    self::c($ch,'Photos',$ph>=10?'pass':($ph>=4?'warn':'fail'),($ph>=10?'10+':$ph).' photos found. Listings with many fresh photos get far more direction requests and clicks.',2,'Profile');
    $hrs=!empty($raw['hours']);
    self::c($ch,'Business hours',$hrs?'pass':'fail',$hrs?'Opening hours listed.':'No opening hours – Google may hide the listing for "open now" searches.',2,'Profile');
    $desc=!empty($raw['description']);
    self::c($ch,'Business description',$desc?'pass':'warn',$desc?'Description present.':'No visible business description / editorial summary.',1,'Profile');
    $cat=$lead['category']!=='';
    self::c($ch,'Primary category',$cat?'pass':'warn',$cat?'Category: '.$lead['category'].'.':'No clear primary category.',1,'Profile');
    $types=count($raw['types']??[]);
    if($types)self::c($ch,'Secondary categories',$types>=3?'pass':'warn',$types.' business categories set. Adding all relevant secondary categories helps appear in more searches.',1,'Visibility');
    if(($raw['status']??'')&&$raw['status']!=='OPERATIONAL')self::c($ch,'Business status','fail','Listing status is '.$raw['status'].'.',3,'Profile');
    return ['score'=>self::score($ch),'checks'=>$ch,'cats'=>self::cats($ch,self::MAP_CATS),'meta'=>['rating'=>$rating,'reviews'=>$rev,'photos'=>$ph,'position'=>$pos,'competitor_avg_reviews'=>$avgRev,'competitor_avg_rating'=>$avgRat]];}}
