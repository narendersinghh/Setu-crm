<?php
return [
"ALTER TABLE campaigns ADD skipped INT NOT NULL DEFAULT 0",
"CREATE TABLE IF NOT EXISTS research_cache (k CHAR(32) PRIMARY KEY,brief MEDIUMTEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"UPDATE settings SET v='narendersinghh/Setu-crm' WHERE k='update_repo' AND v IN ('nilesh-dn/anvesha','')",
"DELETE FROM settings WHERE k='update_cache'",
function(){if(setting("app_url")===""&&cfg("url"))Settings::set("app_url",rtrim((string)cfg("url"),"/"));},
];
