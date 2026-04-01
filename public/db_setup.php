<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

define('DB_HOST', getenv('MYSQLHOST')     ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER')     ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'kingcuts_db');
define('DB_PORT', getenv('MYSQLPORT')     ?: 3306);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
if($conn->connect_error){
    echo json_encode(array('error'=>'Connection failed: '.$conn->connect_error));
    exit;
}

// Create database if not exists
$conn->query("CREATE DATABASE IF NOT EXISTS `".DB_NAME."`");
$conn->select_db(DB_NAME);
$conn->set_charset('utf8');

$tables = array(
"CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",
"CREATE TABLE IF NOT EXISTS barbers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(100) DEFAULT 'Berber',
    experience VARCHAR(50),
    specialties TEXT,
    photo VARCHAR(255),
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",
"CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(100),
    client_phone VARCHAR(30),
    client_email VARCHAR(100),
    barber_name VARCHAR(100),
    service_name VARCHAR(100),
    price VARCHAR(20),
    booking_date DATE,
    booking_time TIME,
    status ENUM('pending','confirmed','completed','cancelled') DEFAULT 'confirmed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",
"CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barber_id INT DEFAULT 0,
    schedule_date DATE,
    blocked_time TIME,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",
"CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(30),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"
);

foreach($tables as $sql){
    $conn->query($sql);
}

// Create admin user
$hash = password_hash('admin123', PASSWORD_BCRYPT);
$conn->query("INSERT IGNORE INTO admins (username, password) VALUES ('admin', '$hash')");

// Insert default barbers if empty
$check = $conn->query("SELECT COUNT(*) as c FROM barbers")->fetch_assoc();
if($check['c'] == 0){
    $conn->query("INSERT INTO barbers (name,role,experience,specialties,active) VALUES
        ('Artan Koci','Master Berber','12 vjet','Fade, Klasik, Mjeker',1),
        ('Blerim Hoxha','Senior Berber','8 vjet','Modern, Skin Fade',1),
        ('Driton Shehu','Berber','5 vjet','Teksture, Curly',1),
        ('Gentian Mema','Grooming Specialist','6 vjet','Grooming, VIP',1)");
}


// Tabelat e reja - barber_accounts dhe reviews
$conn->query("CREATE TABLE IF NOT EXISTS barber_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barber_id INT NOT NULL UNIQUE,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    service VARCHAR(100),
    text TEXT NOT NULL,
    rating INT DEFAULT 5,
    approved TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

echo json_encode(array('success'=>true, 'message'=>'Database u konfigurua! Hyr si admin/admin123'));
?>