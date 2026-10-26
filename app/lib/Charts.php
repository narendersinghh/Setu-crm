<?php
// Vector charts (SVG) for the PDF report – drawn at 2x for crisp output
final class Charts{
  const F="font-family='Inter'";
  const PAL=['#3B82F6','#10B981','#F59E0B','#8B5CF6','#14B8A6','#EF4444','#EC4899','#6366F1'];
  static function col($s){return $s>=75?'#10B981':($s>=50?'#F59E0B':'#EF4444');}
  static function soft($s){return $s>=75?'#D1FAE5':($s>=50?'#FEF3C7':'#FEE2E2');}
  private static function svg($w,$h,$body){return Illus::uri("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 $w $h' width='$w' height='$h'>$body</svg>");}
  static function x($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_XML1,'UTF-8');}
  private static function arc($cx,$cy,$r,$f){$f=max(0,min(.9999,$f));$pt=function($t)use($cx,$cy,$r){$a=$t*2*M_PI-M_PI/2;return round($cx+$r*cos($a),2).' '.round($cy+$r*sin($a),2);};return "M$cx ".($cy-$r).($f>.5?" A$r $r 0 0 1 ".$pt(.5)." A$r $r 0 0 1 ".$pt($f):" A$r $r 0 0 1 ".$pt($f));}
  // Ring gauge with score in the centre
  static function gauge($score,$label='',$size=200,$track='#EEF1F6',$color=null){
    $s=max(0,min(100,(int)$score));$c=$color?:self::col($s);$cx=$cy=$size/2;$sw=$size*.11;$r=$cx-$sw/2-2;$F=self::F;
    $arc=$s>=100?"<circle cx='$cx' cy='$cy' r='$r' fill='none' stroke='$c' stroke-width='$sw'/>":($s>0?"<path d='".self::arc($cx,$cy,$r,$s/100)."' fill='none' stroke='$c' stroke-width='$sw' stroke-linecap='round'/>":'');
    return self::svg($size,$size,"<circle cx='$cx' cy='$cy' r='$r' fill='none' stroke='$track' stroke-width='$sw'/>$arc<text x='$cx' y='".round($cy+$size*.09)."' font-size='".round($size*.27)."' font-weight='bold' text-anchor='middle' fill='#0F172A' $F>$s</text>".($label!==''?"<text x='$cx' y='".round($cy+$size*.22)."' font-size='".round($size*.075)."' text-anchor='middle' fill='#64748B' $F>".self::x($label)."</text>":''));}
  // Horizontal score bars with track and value label
  static function bars($items,$w=640){
    $h=count($items)*44+6;$o='';$y=4;$F=self::F;$lw=170;$bw=$w-$lw-56;
    foreach($items as $label=>$v){$v=(int)$v;$c=self::col($v);$fw=max(14,round($bw*$v/100));
      $o.="<text x='0' y='".($y+20)."' font-size='15' fill='#334155' $F>".self::x($label)."</text><rect x='$lw' y='".($y+6)."' width='$bw' height='18' rx='9' fill='#EEF1F6'/><rect x='$lw' y='".($y+6)."' width='$fw' height='18' rx='9' fill='$c'/><text x='".($w-4)."' y='".($y+21)."' font-size='16' font-weight='bold' text-anchor='end' fill='#0F172A' $F>$v</text>";$y+=44;}
    return self::svg($w,$h,$o);}
  // Compare a value across businesses, highlighting "you"
  static function compare($rows,$unit='',$w=560,$hl='#F59E0B',$base='#CBD5E1'){
    $max=max(1,max(array_map(fn($r)=>(float)$r[1],$rows)));$h=count($rows)*42+6;$o='';$y=4;$F=self::F;$lw=200;$bw=$w-$lw-70;
    foreach($rows as [$label,$v,$me]){$fw=max(6,round($bw*(float)$v/$max));$c=$me?$hl:$base;
      $o.="<text x='0' y='".($y+21)."' font-size='14' fill='".($me?'#0F172A':'#475569')."' font-weight='".($me?'bold':'normal')."' $F>".self::x(mb_strimwidth($label,0,26,'…'))."</text><rect x='$lw' y='".($y+7)."' width='$fw' height='20' rx='6' fill='$c'/><text x='".($lw+$fw+8)."' y='".($y+22)."' font-size='14' font-weight='bold' fill='#0F172A' $F>".self::x($v.$unit)."</text>";$y+=42;}
    return self::svg($w,$h,$o);}
  // Grouped "now vs potential" columns
  static function columns($cols,$w=520,$h=300){
    $max=max(1,max(array_map(fn($c)=>(float)$c[1],$cols)));$n=count($cols);$slot=($w-20)/$n;$bw=min(120,$slot*.55);$F=self::F;$base=$h-46;
    $o="<path d='M10 $base H".($w-10)."' stroke='#CBD5E1' stroke-width='2'/>";
    for($g=1;$g<=3;$g++){$gy=round($base-($base-30)*$g/3);$o.="<path d='M10 $gy H".($w-10)."' stroke='#EEF1F6' stroke-width='1.5'/>";}
    foreach($cols as $i=>[$label,$v,$c,$txt]){$x=10+$i*$slot+($slot-$bw)/2;$bh=max(4,round(($base-40)*(float)$v/$max));
      $o.="<rect x='$x' y='".($base-$bh)."' width='$bw' height='$bh' rx='10' fill='$c'/><text x='".($x+$bw/2)."' y='".($base-$bh-10)."' font-size='18' font-weight='bold' text-anchor='middle' fill='#0F172A' $F>".self::x($txt)."</text><text x='".($x+$bw/2)."' y='".($base+26)."' font-size='14' text-anchor='middle' fill='#475569' $F>".self::x($label)."</text>";}
    return self::svg($w,$h,$o);}
  // Customer funnel: searches → views → actions
  static function funnel($steps,$w=520){
    $h=count($steps)*70;$o='';$F=self::F;$n=count($steps);
    foreach($steps as $i=>[$label,$val,$c]){$top=$w-$i*($w*.18);$bot=$w-($i+1)*($w*.18);$y=$i*70;$x1=($w-$top)/2;$x2=($w-$bot)/2;
      $o.="<path d='M$x1 $y H".($x1+$top)." L".($x2+$bot)." ".($y+62)." H$x2 Z' fill='$c'/><text x='".($w/2)."' y='".($y+30)."' font-size='19' font-weight='bold' text-anchor='middle' fill='#FFFFFF' $F>".self::x($val)."</text><text x='".($w/2)."' y='".($y+50)."' font-size='12.5' text-anchor='middle' fill='#FFFFFF' $F>".self::x($label)."</text>";}
    return self::svg($w,$h,$o);}
  // Pass / warn / fail donut
  static function donut($pass,$warn,$fail,$size=180){
    $t=max(1,$pass+$warn+$fail);$cx=$cy=$size/2;$r=$size*.38;$sw=$size*.16;$o="<circle cx='$cx' cy='$cy' r='$r' fill='none' stroke='#EEF1F6' stroke-width='$sw'/>";$F=self::F;$acc=0;
    foreach([[$pass,'#10B981'],[$warn,'#F59E0B'],[$fail,'#EF4444']] as [$v,$c]){if(!$v)continue;$len=2*M_PI*$r;$seg=$len*$v/$t;$o.="<circle cx='$cx' cy='$cy' r='$r' fill='none' stroke='$c' stroke-width='$sw' stroke-dasharray='".round($seg,2)." ".round($len,2)."' stroke-dashoffset='".round(-$acc,2)."' transform='rotate(-90 $cx $cy)'/>";$acc+=$seg;}
    return self::svg($size,$size,$o."<text x='$cx' y='".($cy+6)."' font-size='".round($size*.17)."' font-weight='bold' text-anchor='middle' fill='#0F172A' $F>$t</text><text x='$cx' y='".($cy+round($size*.13))."' font-size='".round($size*.07)."' text-anchor='middle' fill='#64748B' $F>checks</text>");}
  // Small status / area icons for the PDF (no font glyphs needed)
  static function icon($kind,$size=22){
    $m=['pass'=>['#10B981',"<path d='M7 12.5l3.2 3.2L17 9' fill='none' stroke='#fff' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/>"],'warn'=>['#F59E0B',"<path d='M12 7v6' stroke='#fff' stroke-width='2.6' stroke-linecap='round'/><circle cx='12' cy='16.6' r='1.5' fill='#fff'/>"],'fail'=>['#EF4444',"<path d='M8.5 8.5l7 7M15.5 8.5l-7 7' stroke='#fff' stroke-width='2.6' stroke-linecap='round'/>"]];
    [$c,$p]=$m[$kind]??$m['warn'];
    return self::svg($size,$size,"<g transform='scale(".($size/24).")'><circle cx='12' cy='12' r='12' fill='$c'/>$p</g>");}
  static function glyph($name,$color='#FFFFFF',$size=28){
    $p=['maps'=>"<path d='M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z'/><circle cx='12' cy='10' r='3'/>",'website'=>"<rect x='3' y='4' width='18' height='16' rx='2'/><path d='M3 9h18'/>",'reviews'=>"<path d='M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z'/>",'content'=>"<path d='M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z'/><path d='M14 2v5h5M8 13h8M8 17h6'/>",'conversion'=>"<circle cx='12' cy='12' r='9'/><circle cx='12' cy='12' r='5'/><circle cx='12' cy='12' r='1.5'/>",'speed'=>"<path d='M13 2 3 14h9l-1 8 10-12h-9l1-8z'/>",'trust'=>"<path d='M20 13c0 5-3.5 7.5-8 9-4.5-1.5-8-4-8-9V6l8-3 8 3z'/>",'social'=>"<circle cx='18' cy='5' r='3'/><circle cx='6' cy='12' r='3'/><circle cx='18' cy='19' r='3'/><path d='m8.6 13.5 6.8 4M15.4 6.5l-6.8 4'/>",'growth'=>"<path d='M3 17l6-6 4 4 8-8M15 7h6v6'/>",'calendar'=>"<rect x='3' y='4' width='18' height='18' rx='2'/><path d='M16 2v4M8 2v4M3 10h18'/>",'idea'=>"<path d='M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7V16h8v-1.3A7 7 0 0 0 12 2z'/>",'phone'=>"<path d='M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.9.6 2.8.7a2 2 0 0 1 1.7 2z'/>",'users'=>"<circle cx='9' cy='7' r='4'/><path d='M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2M16 3.1a4 4 0 0 1 0 7.8M21 21v-2a4 4 0 0 0-3-3.9'/>"][$name]??"<circle cx='12' cy='12' r='9'/>";
    return self::svg($size,$size,"<g transform='scale(".($size/24).")' fill='none' stroke='$color' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>$p</g>");}
  // Stacked problem bars per area: rows [label, problems, warnings]
  static function issues($rows,$w=600){
    $max=max(1,max(array_map(fn($r)=>$r[1]+$r[2],$rows)));$h=count($rows)*46+6;$o='';$y=4;$F=self::F;$lw=190;$bw=$w-$lw-60;
    foreach($rows as [$label,$f,$wn]){$fw=$f?max(10,round($bw*$f/$max)):0;$ww=$wn?max(10,round($bw*$wn/$max)):0;
      $o.="<text x='0' y='".($y+22)."' font-size='15' fill='#334155' $F>".self::x(mb_strimwidth($label,0,24,'…'))."</text><rect x='$lw' y='".($y+7)."' width='$bw' height='22' rx='7' fill='#F1F5F9'/>".($fw?"<rect x='$lw' y='".($y+7)."' width='$fw' height='22' rx='7' fill='#EF4444'/>":'').($ww?"<rect x='".($lw+$fw+($fw?3:0))."' y='".($y+7)."' width='$ww' height='22' rx='7' fill='#F59E0B'/>":'')."<text x='".($w-4)."' y='".($y+24)."' font-size='16' font-weight='bold' text-anchor='end' fill='#0F172A' $F>".($f+$wn)."</text>";$y+=46;}
    return self::svg($w,$h,$o);}}
