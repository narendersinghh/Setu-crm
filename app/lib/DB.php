<?php
final class DB{
  private static $p;
  static function pdo(){
    if(!self::$p){
      $c=$GLOBALS['CFG']['db'];
      self::$p=new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
      // Match the connection collation to the database so text comparisons never mix collations (MySQL error 1267)
      $col=(string)self::$p->query('SELECT @@collation_database')->fetchColumn();
      if(preg_match('/^utf8mb4_[a-z0-9_]+$/',$col))self::$p->exec("SET NAMES utf8mb4 COLLATE $col");}
    return self::$p;}
  static function q($sql,$a=[]){$s=self::pdo()->prepare($sql);$s->execute(array_values($a));return $s;}
  static function all($sql,$a=[]){return self::q($sql,$a)->fetchAll();}
  static function row($sql,$a=[]){return self::q($sql,$a)->fetch()?:null;}
  static function val($sql,$a=[]){$v=self::q($sql,$a)->fetchColumn();return $v===false?null:$v;}
  static function insert($t,$d){
    $k=array_keys($d);
    self::q('INSERT INTO `'.$t.'` (`'.implode('`,`',$k).'`) VALUES ('.implode(',',array_fill(0,count($k),'?')).')',$d);
    return (int)self::pdo()->lastInsertId();}
  static function update($t,$d,$where,$args=[]){
    if(!$d)return 0;
    $set=implode(',',array_map(fn($k)=>"`$k`=?",array_keys($d)));
    return self::q("UPDATE `$t` SET $set WHERE $where",array_merge(array_values($d),$args))->rowCount();}
  static function in($ids){$ids=array_values(array_map('intval',(array)$ids));return [$ids?implode(',',array_fill(0,count($ids),'?')):'NULL',$ids];}}
