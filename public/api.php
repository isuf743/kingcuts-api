<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
error_reporting(0);
ini_set('display_errors', 0);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'kingcuts_db');

function getDB(){
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if($conn->connect_error){
        http_response_code(500);
        echo json_encode(array('error'=>'DB error: '.$conn->connect_error));
        exit;
    }
    $conn->set_charset('utf8');
    return $conn;
}

function respond($data, $code=200){
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if(!is_array($input)) $input = array();

if($action === 'admin_login' && $method === 'POST'){
    $username = trim(isset($input['username']) ? $input['username'] : '');
    $password = trim(isset($input['password']) ? $input['password'] : '');
    if(!$username || !$password) respond(array('error'=>'Plotesoni fushat'), 400);
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM admins WHERE username=?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    if($admin && (password_verify($password, $admin['password']) || $password === $admin['password'])){
        respond(array('success'=>true, 'token'=>base64_encode($username.':'.time())));
    }
    respond(array('error'=>'Username ose password i gabuar'), 401);
}

if($action === 'check_admin' && $method === 'POST'){
    $username = trim(isset($input['username']) ? $input['username'] : '');
    $password = trim(isset($input['password']) ? $input['password'] : '');
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM admins WHERE username=?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    if($admin && (password_verify($password, $admin['password']) || $password === $admin['password'])){
        respond(array('success'=>true));
    }
    respond(array('error'=>'Gabim'), 401);
}

if($action === 'setup_admin'){
    $db = getDB();
    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO admins (username,password) VALUES ('admin',?) ON DUPLICATE KEY UPDATE password=?");
    $stmt->bind_param('ss', $hash, $hash);
    $stmt->execute();
    respond(array('success'=>true, 'msg'=>'admin / admin123'));
}

if($action === 'get_bookings' && $method === 'GET'){
    $db = getDB();
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $date = isset($_GET['date']) ? $_GET['date'] : '';
    $sql = "SELECT * FROM bookings WHERE 1=1";
    $params = array(); $types = '';
    if($status){ $sql .= " AND status=?"; $params[] = $status; $types .= 's'; }
    if($date){ $sql .= " AND booking_date=?"; $params[] = $date; $types .= 's'; }
    $sql .= " ORDER BY booking_date ASC, booking_time ASC";
    $stmt = $db->prepare($sql);
    if($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    respond($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

if($action === 'add_booking' && $method === 'POST'){
    $db = getDB();
    $client_name    = isset($input['client_name'])       ? $input['client_name']       : '';
    $client_phone   = isset($input['client_phone'])      ? $input['client_phone']      : '';
    $client_email   = isset($input['client_email'])      ? $input['client_email']      : '';
    $barber_name    = isset($input['barber_name'])       ? $input['barber_name']       : '';
    $service_name   = isset($input['service_name'])      ? $input['service_name']      : '';
    $price          = isset($input['price'])             ? $input['price']             : '';
    $booking_date   = isset($input['booking_date'])      ? $input['booking_date']      : '';
    $booking_time   = isset($input['booking_time'])      ? $input['booking_time']      : '';
    $duration_mins  = intval(isset($input['duration_minutes']) ? $input['duration_minutes'] : 30);
    if($duration_mins < 30) $duration_mins = 30;
    if(!$client_name)  respond(array('error'=>'Emri mungon'), 400);
    if(!$client_phone) respond(array('error'=>'Telefoni mungon'), 400);
    if(!$booking_date) respond(array('error'=>'Data mungon'), 400);
    if(!$booking_time) respond(array('error'=>'Ora mungon'), 400);

    // Services that need 2 slots (30 min each)
    $two_slot_services = array('Prerje + Mjeker', 'King Experience', 'Grooming i Plote', 'Trajtim Lekure');
    $slots_needed = in_array($service_name, $two_slot_services) ? 2 : 1;

    $start_parts = explode(':', $booking_time);
    $start_mins = intval($start_parts[0]) * 60 + intval($start_parts[1]);
    $all_slots = array();
    for($i = 0; $i < $slots_needed; $i++){
        $slot_mins = $start_mins + ($i * 30);
        $h = str_pad(floor($slot_mins / 60), 2, '0', STR_PAD_LEFT);
        $mn = str_pad($slot_mins % 60, 2, '0', STR_PAD_LEFT);
        $all_slots[] = $h . ':' . $mn . ':00';
    }

    // Check all slots are free for this barber
    foreach($all_slots as $slot){
        $chk = $db->prepare("SELECT id FROM bookings WHERE booking_date=? AND booking_time=? AND barber_name=? AND status!='cancelled'");
        $chk->bind_param('sss', $booking_date, $slot, $barber_name);
        $chk->execute();
        if($chk->get_result()->num_rows > 0){
            $slot_display = substr($slot, 0, 5);
            respond(array('error'=>'Ora '.$slot_display.' eshte e zene!'), 400);
        }
        $blk = $db->prepare("SELECT id FROM schedules WHERE schedule_date=? AND blocked_time=? AND (barber_id=(SELECT id FROM barbers WHERE name=? LIMIT 1) OR barber_id=0)");
        $blk->bind_param('sss', $booking_date, $slot, $barber_name);
        $blk->execute();
        if($blk->get_result()->num_rows > 0){
            $slot_display = substr($slot, 0, 5);
            respond(array('error'=>'Ora '.$slot_display.' eshte e bllokuar!'), 400);
        }
    }

    // Insert all slots as bookings
    $status = 'confirmed';
    $first_id = 0;
    $stmt = $db->prepare("INSERT INTO bookings (client_name,client_phone,client_email,barber_name,service_name,price,booking_date,booking_time,status) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach($all_slots as $i => $slot){
        // Only first slot has real price, others have empty price to avoid double counting
        $slot_price = ($i === 0) ? $price : '(zene)';
        $slot_service = ($i === 0) ? $service_name : $service_name.' (vazhdim)';
        $stmt->bind_param('sssssssss', $client_name,$client_phone,$client_email,$barber_name,$slot_service,$slot_price,$booking_date,$slot,$status);
        $stmt->execute();
        if($i === 0) $first_id = $db->insert_id;
    }
    if($first_id > 0) respond(array('success'=>true, 'id'=>$first_id));
    else respond(array('error'=>$db->error), 500);
}

if($action === 'update_booking' && $method === 'POST'){
    $db = getDB();
    $id = intval(isset($input['id']) ? $input['id'] : 0);
    $status = isset($input['status']) ? $input['status'] : '';
    $allowed = array('pending','confirmed','completed','cancelled');
    if(!$id || !in_array($status, $allowed)) respond(array('error'=>'Te dhena te pavlefshme'), 400);
    $stmt = $db->prepare("UPDATE bookings SET status=? WHERE id=?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    respond(array('success'=>true));
}

if($action === 'delete_booking' && $method === 'POST'){
    $db = getDB();
    $id = intval(isset($input['id']) ? $input['id'] : 0);
    if(!$id) respond(array('error'=>'ID e pavlefshme'), 400);
    $stmt = $db->prepare("DELETE FROM bookings WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    respond(array('success'=>true));
}

if($action === 'get_barbers' && $method === 'GET'){
    $db = getDB();
    respond($db->query("SELECT * FROM barbers ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC));
}

if($action === 'add_barber' && $method === 'POST'){
    $db = getDB();
    $name = isset($input['name']) ? $input['name'] : '';
    if(!$name) respond(array('error'=>'Emri mungon'), 400);
    $role = isset($input['role']) ? $input['role'] : 'Berber';
    $exp  = isset($input['experience']) ? $input['experience'] : '';
    $spec = isset($input['specialties']) ? $input['specialties'] : '';
    $stmt = $db->prepare("INSERT INTO barbers (name,role,experience,specialties) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss', $name,$role,$exp,$spec);
    if($stmt->execute()) respond(array('success'=>true, 'id'=>$db->insert_id));
    else respond(array('error'=>$db->error), 500);
}

if($action === 'toggle_barber' && $method === 'POST'){
    $db = getDB();
    $id = intval(isset($input['id']) ? $input['id'] : 0);
    if(!$id) respond(array('error'=>'ID e pavlefshme'), 400);
    $row = $db->query("SELECT active FROM barbers WHERE id=$id")->fetch_assoc();
    if(!$row) respond(array('error'=>'Berberi nuk u gjet'), 404);
    $newActive = ($row['active'] == 1) ? 0 : 1;
    $db->query("UPDATE barbers SET active=$newActive WHERE id=$id");
    respond(array('success'=>true, 'active'=>$newActive, 'id'=>$id));
}

if($action === 'delete_barber' && $method === 'POST'){
    $db = getDB();
    $id = intval(isset($input['id']) ? $input['id'] : 0);
    if(!$id) respond(array('error'=>'ID e pavlefshme'), 400);
    $stmt = $db->prepare("DELETE FROM barbers WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    respond(array('success'=>true));
}

if($action === 'get_stats' && $method === 'GET'){
    $db = getDB();
    $total    = $db->query("SELECT COUNT(*) as c FROM bookings")->fetch_assoc()['c'];
    $today    = $db->query("SELECT COUNT(*) as c FROM bookings WHERE booking_date=CURDATE()")->fetch_assoc()['c'];
    $pending  = $db->query("SELECT COUNT(*) as c FROM bookings WHERE status='pending'")->fetch_assoc()['c'];
    $confirmed= $db->query("SELECT COUNT(*) as c FROM bookings WHERE status='confirmed'")->fetch_assoc()['c'];
    $completed= $db->query("SELECT COUNT(*) as c FROM bookings WHERE status='completed'")->fetch_assoc()['c'];
    $cancelled= $db->query("SELECT COUNT(*) as c FROM bookings WHERE status='cancelled'")->fetch_assoc()['c'];
    $clients  = $db->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
    $barbers  = $db->query("SELECT COUNT(*) as c FROM barbers WHERE active=1")->fetch_assoc()['c'];
    $rev      = $db->query("SELECT SUM(CAST(REPLACE(REPLACE(price,' L',''),',','') AS DECIMAL(10,2))) as t FROM bookings WHERE status='completed' AND MONTH(booking_date)=MONTH(CURDATE())")->fetch_assoc();
    $weekly   = $db->query("SELECT booking_date, COUNT(*) as count FROM bookings WHERE booking_date>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY booking_date ORDER BY booking_date ASC")->fetch_all(MYSQLI_ASSOC);
    respond(array('total_bookings'=>$total,'today_bookings'=>$today,'pending'=>$pending,'confirmed'=>$confirmed,'completed'=>$completed,'cancelled'=>$cancelled,'total_clients'=>$clients,'total_barbers'=>$barbers,'monthly_revenue'=>($rev?$rev['t']:0),'weekly'=>$weekly));
}

if($action === 'get_clients' && $method === 'GET'){
    $db = getDB();
    respond($db->query("SELECT id,name,email,phone,created_at FROM users ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC));
}

if($action === 'get_schedules' && $method === 'GET'){
    $db = getDB();
    respond($db->query("SELECT s.*, b.name as barber_name FROM schedules s LEFT JOIN barbers b ON s.barber_id=b.id ORDER BY s.schedule_date ASC, s.blocked_time ASC")->fetch_all(MYSQLI_ASSOC));
}

if($action === 'block_time' && $method === 'POST'){
    $db = getDB();
    $barber_id = intval(isset($input['barber_id']) ? $input['barber_id'] : 0);
    $date = isset($input['date']) ? $input['date'] : '';
    $time = isset($input['time']) ? $input['time'] : '';
    $note = isset($input['note']) ? $input['note'] : '';
    if(!$date || !$time) respond(array('error'=>'Data dhe ora jane te detyrueshme'), 400);
    $dup = $db->prepare("SELECT id FROM schedules WHERE schedule_date=? AND blocked_time=? AND barber_id=?");
    $dup->bind_param('ssi', $date, $time, $barber_id);
    $dup->execute();
    if($dup->get_result()->num_rows > 0) respond(array('error'=>'Kjo ore eshte tashme e bllokuar!'), 400);
    $stmt = $db->prepare("INSERT INTO schedules (barber_id,schedule_date,blocked_time,note) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $barber_id, $date, $time, $note);
    if($stmt->execute()) respond(array('success'=>true, 'id'=>$db->insert_id));
    else respond(array('error'=>$db->error), 500);
}

if($action === 'unblock_time' && $method === 'POST'){
    $db = getDB();
    $id = intval(isset($input['id']) ? $input['id'] : 0);
    if(!$id) respond(array('error'=>'ID e pavlefshme'), 400);
    $stmt = $db->prepare("DELETE FROM schedules WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    respond(array('success'=>true));
}

if($action === 'get_booked_slots' && $method === 'GET'){
    $db = getDB();
    $date = isset($_GET['date']) ? $_GET['date'] : '';
    $barber_id = intval(isset($_GET['barber_id']) ? $_GET['barber_id'] : 0);
    if(!$date) respond(array());
    if($barber_id > 0){
        $booked = $db->query("SELECT booking_time FROM bookings WHERE booking_date='$date' AND barber_name=(SELECT name FROM barbers WHERE id=$barber_id LIMIT 1) AND status!='cancelled'")->fetch_all(MYSQLI_ASSOC);
        $blocked = $db->query("SELECT blocked_time as booking_time FROM schedules WHERE schedule_date='$date' AND barber_id=$barber_id")->fetch_all(MYSQLI_ASSOC);
    } else {
        $booked = $db->query("SELECT booking_time FROM bookings WHERE booking_date='$date' AND status!='cancelled'")->fetch_all(MYSQLI_ASSOC);
        $blocked = $db->query("SELECT blocked_time as booking_time FROM schedules WHERE schedule_date='$date'")->fetch_all(MYSQLI_ASSOC);
    }
    $times = array();
    foreach(array_merge($booked,$blocked) as $r) $times[] = substr($r['booking_time'],0,5);
    respond(array_values(array_unique($times)));
}

respond(array('error'=>'Action e panjohur: '.$action), 404);
?>