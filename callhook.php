<?php
// AI voice provider webhook: callhook.php?t=CALL_TOKEN
require __DIR__.'/app/bootstrap.php';
session_write_close();
$t=preg_replace('/[^a-f0-9]/','',$_GET['t']??'');
$raw=file_get_contents('php://input');
$d=json_decode($raw,true);
if(!$t&&is_array($d)){$m=$d['call']['metadata']['hook']??($d['metadata']['hook']??'');if(preg_match('/t=([a-f0-9]+)/',(string)$m,$x))$t=$x[1];}
$call=$t?DB::row('SELECT * FROM calls WHERE token=?',[$t]):null;
if(!$call){http_response_code(404);exit('Unknown call');}
if(is_array($d))try{Voice::webhook($call,$d);}catch(Throwable $e){logmsg('Call webhook: '.$e->getMessage());}
json_out(['ok'=>true]);
