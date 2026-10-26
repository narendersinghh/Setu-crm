<?php
final class Settings{
  private static $c=null;
  const SECRET='/(_key|_pass|_password|_token|_secret)$|^ai_key_/';
  static function all(){
    if(self::$c===null){
      self::$c=[];
      foreach(DB::all('SELECT k,v FROM settings') as $r)self::$c[$r['k']]=preg_match(self::SECRET,$r['k'])?self::dec($r['v']):$r['v'];}
    return self::$c;}
  static function get($k,$d=''){$a=self::all();return isset($a[$k])&&$a[$k]!==''?$a[$k]:$d;}
  static function set($k,$v){
    $v=is_array($v)?json_encode($v):(string)$v;
    $s=preg_match(self::SECRET,$k)?self::enc($v):$v;
    DB::q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)',[$k,$s]);
    if(self::$c!==null)self::$c[$k]=$v;}
  static function many($a){foreach($a as $k=>$v)self::set($k,$v);}
  private static function key(){return hash('sha256',$GLOBALS['CFG']['app_key'],true);}
  static function enc($v){
    if($v==='')return '';
    $iv=random_bytes(16);
    return 'enc:'.base64_encode($iv.openssl_encrypt($v,'aes-256-cbc',self::key(),OPENSSL_RAW_DATA,$iv));}
  static function dec($v){
    if(strncmp((string)$v,'enc:',4))return (string)$v;
    $b=base64_decode(substr($v,4));
    return (string)openssl_decrypt(substr($b,16),'aes-256-cbc',self::key(),OPENSSL_RAW_DATA,substr($b,0,16));}}
