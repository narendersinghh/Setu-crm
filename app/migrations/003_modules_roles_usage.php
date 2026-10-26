<?php
return [
"ALTER TABLE users ADD role VARCHAR(10) NOT NULL DEFAULT 'sales',ADD active TINYINT(1) NOT NULL DEFAULT 1,ADD phone VARCHAR(40) DEFAULT '',ADD last_login DATETIME NULL",
"UPDATE users SET role='admin'",
"UPDATE users u JOIN (SELECT MIN(id) id FROM users) m ON m.id=u.id SET u.role='super'",
"CREATE TABLE IF NOT EXISTS api_usage (day DATE NOT NULL,service VARCHAR(30) NOT NULL,op VARCHAR(40) NOT NULL DEFAULT '',calls INT NOT NULL DEFAULT 0,units_in BIGINT NOT NULL DEFAULT 0,units_out BIGINT NOT NULL DEFAULT 0,cost DECIMAL(14,6) NOT NULL DEFAULT 0,errors INT NOT NULL DEFAULT 0,PRIMARY KEY(day,service,op)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"ALTER TABLE appointments ADD gcal_id VARCHAR(255) DEFAULT '',ADD meet_link VARCHAR(500) DEFAULT '',ADD user_id INT UNSIGNED NULL",
function(){$smtp=(string)DB::val("SELECT v FROM settings WHERE k='smtp_host'");DB::q("INSERT IGNORE INTO settings (k,v) VALUES ('mod_prospecting','1'),('mail_transport',?)",[$smtp!==''?'smtp':'php']);},
function(){Seed::upgradeReports();},
];
