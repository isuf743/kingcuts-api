<?php

require 'settings.php';
require 'mail.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONS (CORS)
if($_SERVER['REQUEST_METHOD'] === 'OPTIONS'){
    http_response_code(200);
    exit;
}

// ---------------- DB ----------------
function getDB(){
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

    if($conn->connect_error){
        respond(['error'=>'DB connection failed'], 500);
    }

    $conn->set_charset('utf8');
    return $conn;
}

// ---------------- RESPONSE ----------------
function respond($data, $code=200){
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------- INPUT ----------------
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$input = json_decode(file_get_contents('php://input'), true);
if(!is_array($input)) $input = [];

// ======================================================
// 🔐 ADMIN LOGIN
// ======================================================
if($action === 'admin_login' && $method === 'POST'){
    $db = getDB();

    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM admins WHERE username=?");
    $stmt->bind_param('s', $username);
    $stmt->execute();

    $admin = $stmt->get_result()->fetch_assoc();

    if($admin && (password_verify($password, $admin['password']) || $password === $admin['password'])){
        respond(['success'=>true]);
    }

    respond(['error'=>'Login failed'], 401);
}

// ======================================================
// 📅 ADD BOOKING + EMAIL
// ======================================================
if($action === 'add_booking' && $method === 'POST'){
    $db = getDB();

    $client_name  = $input['client_name'] ?? '';
    $client_phone = $input['client_phone'] ?? '';
    $client_email = $input['client_email'] ?? '';
    $barber_name  = $input['barber_name'] ?? '';
    $service_name = $input['service_name'] ?? '';
    $price        = $input['price'] ?? '';
    $booking_date = $input['booking_date'] ?? '';
    $booking_time = $input['booking_time'] ?? '';

    if(!$client_name || !$client_phone || !$booking_date || !$booking_time){
        respond(['error'=>'Missing fields'], 400);
    }

    // CHECK SLOT
    $check = $db->prepare("SELECT id FROM bookings 
        WHERE booking_date=? AND booking_time=? 
        AND barber_name=? AND status!='cancelled'");
    $check->bind_param('sss', $booking_date, $booking_time, $barber_name);
    $check->execute();

    if($check->get_result()->num_rows > 0){
        respond(['error'=>'Time not available'], 400);
    }

    // INSERT
    $status = 'confirmed';
    $stmt = $db->prepare("
        INSERT INTO bookings 
        (client_name,client_phone,client_email,barber_name,service_name,price,booking_date,booking_time,status) 
        VALUES (?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        'sssssssss',
        $client_name,
        $client_phone,
        $client_email,
        $barber_name,
        $service_name,
        $price,
        $booking_date,
        $booking_time,
        $status
    );

    $stmt->execute();
    $id = $db->insert_id;

    if($id > 0){

        // 📧 EMAIL CUSTOMER
        if(!empty($client_email)){
            sendEmail(
                $client_email,
                "Rezervimi juaj - King Cuts",
                "
                Pershendetje $client_name,<br><br>
                Rezervimi juaj u konfirmua.<br>
                Sherbimi: $service_name<br>
                Berberi: $barber_name<br>
                Data: $booking_date<br>
                Ora: $booking_time
                "
            );
        }

        // 📧 EMAIL ADMIN
        sendEmail(
            ADMIN_EMAIL,
            "Rezervim i ri",
            "
            Klient: $client_name<br>
            Telefon: $client_phone<br>
            Sherbimi: $service_name<br>
            Data: $booking_date<br>
            Ora: $booking_time
            "
        );

        respond(['success'=>true, 'id'=>$id]);
    }

    respond(['error'=>'Insert failed'], 500);
}

// ======================================================
// 📊 GET BOOKINGS
// ======================================================
if($action === 'get_bookings' && $method === 'GET'){
    $db = getDB();

    $result = $db->query("
        SELECT * FROM bookings 
        ORDER BY booking_date ASC, booking_time ASC
    ");

    respond($result->fetch_all(MYSQLI_ASSOC));
}

// ======================================================
// ❌ DELETE BOOKING
// ======================================================
if($action === 'delete_booking' && $method === 'POST'){
    $db = getDB();

    $id = intval($input['id'] ?? 0);

    if(!$id) respond(['error'=>'Invalid ID'], 400);

    $stmt = $db->prepare("DELETE FROM bookings WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    respond(['success'=>true]);
}

// ======================================================
// ⚠️ UNKNOWN ACTION
// ======================================================
respond(['error'=>'Invalid action'], 404);