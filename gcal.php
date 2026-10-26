<?php
// Google Calendar OAuth: gcal.php?connect=1 (logged-in admin) → Google → gcal.php?code=…
require __DIR__.'/app/bootstrap.php';
if(!can('settings')){http_response_code(403);exit('Please sign in as an admin.');}
try{
  if(isset($_GET['connect'])){if(!setting('gcal_client_id')||!setting('gcal_client_secret'))throw new Exception('Save your Google OAuth Client ID and Secret first.');redirect(GCal::authUrl());}
  if(isset($_GET['error']))throw new Exception('Google: '.$_GET['error']);
  if(!hash_equals((string)setting('gcal_state'),(string)($_GET['state']??'')))throw new Exception('Security check failed – please try connecting again.');
  GCal::exchange($_GET['code']??'');
  flash('Google Calendar connected'.(setting('gcal_account')?' ('.e(setting('gcal_account')).')':'').'.');}
catch(Throwable $e){flash(e($e->getMessage()),'err');}
redirect(url('settings',['tab'=>'appointments']));
