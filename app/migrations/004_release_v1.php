<?php
return [
"ALTER TABLE campaigns ADD skipped INT NOT NULL DEFAULT 0",
"CREATE TABLE IF NOT EXISTS research_cache (k CHAR(32) PRIMARY KEY,brief MEDIUMTEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"UPDATE settings SET v='narendersinghh/Setu-crm' WHERE k='update_repo' AND v IN ('nilesh-dn/anvesha','')",
"DELETE FROM settings WHERE k='update_cache'",
function(){if(setting("app_url")===""&&cfg("url"))Settings::set("app_url",rtrim((string)cfg("url"),"/"));},
// Faster sidebar counts, lead lists, follow-ups, automations and calendar
"ALTER TABLE leads ADD KEY lead_status (is_lead,status),ADD KEY src_lead (source,is_lead),ADD KEY follow_up (follow_up_at),ADD KEY owner (assigned_to),ADD KEY stage_act (stage,last_activity)",
"ALTER TABLE messages ADD KEY lead_dir (lead_id,direction,created_at)",
"ALTER TABLE appointments ADD KEY st_start (status,start_at)",
"ALTER TABLE automation_runs ADD KEY lead_auto (lead_id,automation_id),ADD KEY st (status)",
"ALTER TABLE jobs ADD KEY type_ref (type,ref_id,status)",
"CREATE TABLE IF NOT EXISTS login_fails (ip VARCHAR(45) PRIMARY KEY,n INT NOT NULL DEFAULT 0,last DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
