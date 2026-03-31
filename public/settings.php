<?php

//settings.php

// DATABASE
define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'kingcuts_db');
define('DB_PORT', getenv('MYSQLPORT') ?: 3306);

// SMTP CONFIG
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'isufselishta57@gmail.com');
define('SMTP_PASS', 'mewr iqhk qjfa ehpt');
define('SMTP_PORT', 587);
define('SMTP_FROM', 'info@email.com');
define('SMTP_NAME', 'King Cuts');

// ADMIN EMAIL
define('ADMIN_EMAIL', 'admin@yourdomain.com');