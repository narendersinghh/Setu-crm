<?php
return [
// Reply detection stays on for installs that already use IMAP
"INSERT IGNORE INTO settings (k,v) SELECT 'imap_on',IF(v<>'','1','0') FROM settings WHERE k='imap_host'",
];
