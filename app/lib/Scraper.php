<?php
final class Scraper{
  static function norm($u){
    $u=trim((string)$u);
    if($u==='')return '';
    if(!preg_match('~^https?://~i',$u))$u='http://'.$u;
    return $u;}
  static function fetch($u){return http(self::norm($u),['timeout'=>25,'max'=>3000000,'public'=>true]);}
  // Find phones, emails and social links on a website (home + contact page)
  static function contacts($site){
    $res=['phones'=>[],'emails'=>[],'socials'=>[],'whatsapp'=>''];
    $r=self::fetch($site);
    if($r['code']<200||$r['code']>=400||$r['body']==='')return $res;
    $pages=[$r['body']];
    if(preg_match_all('~href=["\']([^"\']*(contact|reach|about)[^"\']*)["\']~i',$r['body'],$m)){
      $link=self::abs($m[1][0],$r['url']);
      if($link&&parse_url($link,PHP_URL_HOST)===parse_url($r['url'],PHP_URL_HOST)){$c=self::fetch($link);if($c['code']===200)$pages[]=$c['body'];}}
    foreach($pages as $h)self::extract($h,$res);
    $res['phones']=array_values(array_unique($res['phones']));
    $res['emails']=array_values(array_unique($res['emails']));
    $res['socials']=array_unique($res['socials']);
    return $res;}
  static function extract($h,&$res){
    if(preg_match_all('~href=["\']tel:([^"\']+)["\']~i',$h,$m))foreach($m[1] as $p){$p=urldecode($p);if(strlen(preg_replace('/\D/','',$p))>=8)$res['phones'][]=trim($p);}
    if(preg_match('~(?:wa\.me/|api\.whatsapp\.com/send\?phone=|whatsapp://send\?phone=)\+?(\d{8,15})~i',$h,$m)){$res['whatsapp']=$m[1];$res['phones'][]='+'.$m[1];}
    $text=html_entity_decode(strip_tags(preg_replace('~<(script|style)[^>]*>.*?</\1>~is',' ',$h)));
    if(!$res['phones']&&preg_match_all('~(?<![\d/])(\+?\d{1,3}[\s.-]?)?(\(?\d{2,5}\)?[\s.-]?)\d{3,5}[\s.-]?\d{3,5}(?![\d/])~',$text,$m)){
      foreach($m[0] as $p){$d=preg_replace('/\D/','',$p);if(strlen($d)>=10&&strlen($d)<=13&&!preg_match('/^(19|20)\d{6}$/',$d))$res['phones'][]=trim($p);}}
    $res['phones']=array_slice($res['phones'],0,5);
    if(preg_match_all('~mailto:([^"\'?\s>]+)~i',$h,$m))foreach($m[1] as $x)$res['emails'][]=strtolower(urldecode($x));
    if(preg_match_all('~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,10}~i',$text.' '.$h,$m))foreach($m[0] as $x)$res['emails'][]=strtolower($x);
    $res['emails']=array_values(array_filter(array_unique($res['emails']),fn($x)=>filter_var($x,FILTER_VALIDATE_EMAIL)&&!preg_match('~\.(png|jpe?g|gif|webp|svg|css|js)$|example\.|sentry|wixpress|domain\.com|yourdomain|email\.com|@2x|godaddy~i',$x)));
    foreach(['facebook'=>'facebook\.com','instagram'=>'instagram\.com','linkedin'=>'linkedin\.com','youtube'=>'youtube\.com','twitter'=>'(?:twitter|x)\.com'] as $k=>$p)if(empty($res['socials'][$k])&&preg_match('~https?://(?:www\.)?'.$p.'/[^"\'\s<>]+~i',$h,$m)&&!preg_match('~sharer|share\?|intent/~i',$m[0]))$res['socials'][$k]=$m[0];}
  static function abs($href,$base){
    if(preg_match('~^(mailto|tel|javascript|#)~i',$href))return '';
    if(preg_match('~^https?://~i',$href))return $href;
    $b=parse_url($base);
    $root=$b['scheme'].'://'.$b['host'];
    if(strpos($href,'//')===0)return $b['scheme'].':'.$href;
    if($href[0]==='/')return $root.$href;
    return rtrim($root.dirname($b['path']??'/'),'/').'/'.$href;}}
