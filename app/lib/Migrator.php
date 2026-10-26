<?php
final class Migrator{
  static function pending(){
    DB::q("CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(120) PRIMARY KEY,ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done=array_column(DB::all('SELECT name FROM migrations'),'name');
    $all=array_map(fn($f)=>basename($f,'.php'),glob(APP.'/migrations/*.php'));
    sort($all);
    return array_values(array_diff($all,$done));}
  static function run(){
    $ran=[];
    foreach(self::pending() as $m){
      $sql=require APP.'/migrations/'.$m.'.php';
      foreach((array)$sql as $s){if(is_callable($s))$s();else DB::pdo()->exec($s);}
      DB::insert('migrations',['name'=>$m]);
      $ran[]=$m;}
    Seed::defaults();
    return $ran;}}
