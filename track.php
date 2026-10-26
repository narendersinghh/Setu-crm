<?php
require __DIR__.'/app/bootstrap.php';
session_write_close();
$t=preg_replace('/[^a-f0-9]/','',$_GET['t']??'');
if($t&&($m=DB::row("SELECT id,lead_id,status FROM messages WHERE token=? AND channel='email'",[$t]))&&$m['status']==='sent'){
  DB::update('messages',['status'=>'opened'],'id=?',[$m['id']]);
  DB::q("UPDATE leads SET email_status='opened' WHERE id=? AND email_status='sent'",[$m['lead_id']]);
  DB::q("UPDATE leads SET stage='opened' WHERE id=? AND stage='contacted'",[$m['lead_id']]);
  event($m['lead_id'],'opened','Email opened','email');}
header('Content-Type: image/gif');header('Cache-Control: no-store');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
Cron::kick();
