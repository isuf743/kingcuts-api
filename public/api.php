<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if($_SERVER['REQUEST_METHOD'] === 'OPTIONS'){ http_response_code(200); exit; }
header('Access-Control-Allow-Headers: Content-Type');
error_reporting(0);
ini_set('display_errors', 0);

define('DB_HOST', getenv('MYSQLHOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'kingcuts_db');

function getDB(){
    $port = intval(getenv('MYSQLPORT') ?: 3306);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
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
    $client_name  = isset($input['client_name'])  ? $input['client_name']  : '';
    $client_phone = isset($input['client_phone']) ? $input['client_phone'] : '';
    $client_email = isset($input['client_email']) ? $input['client_email'] : '';
    $barber_name  = isset($input['barber_name'])  ? $input['barber_name']  : '';
    $service_name = isset($input['service_name']) ? $input['service_name'] : '';
    $price        = isset($input['price'])        ? $input['price']        : '';
    $booking_date = isset($input['booking_date']) ? $input['booking_date'] : '';
    $booking_time = isset($input['booking_time']) ? $input['booking_time'] : '';
    if(!$client_name)  respond(array('error'=>'Emri mungon'), 400);
    if(!$client_phone) respond(array('error'=>'Telefoni mungon'), 400);
    if(!$booking_date) respond(array('error'=>'Data mungon'), 400);
    if(!$booking_time) respond(array('error'=>'Ora mungon'), 400);
    $chk = $db->prepare("SELECT id FROM bookings WHERE booking_date=? AND booking_time=? AND barber_name=? AND status!='cancelled'");
    $chk->bind_param('sss', $booking_date, $booking_time, $barber_name);
    $chk->execute();
    if($chk->get_result()->num_rows > 0) respond(array('error'=>'Kjo ore eshte e zene!'), 400);
    $blk = $db->prepare("SELECT id FROM schedules WHERE schedule_date=? AND blocked_time=? AND (barber_id=(SELECT id FROM barbers WHERE name=?) OR barber_id=0)");
    $blk->bind_param('sss', $booking_date, $booking_time, $barber_name);
    $blk->execute();
    if($blk->get_result()->num_rows > 0) respond(array('error'=>'Kjo ore eshte e bllokuar!'), 400);
    $status = 'pending';
    $stmt = $db->prepare("INSERT INTO bookings (client_name,client_phone,client_email,barber_name,service_name,price,booking_date,booking_time,status) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssssss', $client_name,$client_phone,$client_email,$barber_name,$service_name,$price,$booking_date,$booking_time,$status);
    if($stmt->execute()) respond(array('success'=>true, 'id'=>$db->insert_id));
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


// SEND EMAIL via Resend
if($action === 'send_email' && $method === 'POST'){
    $to      = isset($input['to_email'])  ? $input['to_email']  : '';
    $name    = isset($input['to_name'])   ? $input['to_name']   : '';
    $service = isset($input['service'])   ? $input['service']   : '';
    $barber  = isset($input['barber'])    ? $input['barber']    : '';
    $date    = isset($input['date'])      ? $input['date']      : '';
    $time    = isset($input['time'])      ? $input['time']      : '';
    $price   = isset($input['price'])     ? $input['price']     : '';
    if(!$to) respond(array('error'=>'Email mungon'), 400);
    $resend_key = getenv('RESEND_API_KEY') ?: 're_GYbmRiyt_JPvoDraeu4SpyFryr1e4TG88';
    $html_body = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
    <div style='background:#1a1a1a;padding:20px;text-align:center;'><h1 style='color:#c9a84c;margin:0;'>&#9986; KING CUTS</h1></div>
    <div style='padding:30px;background:#f9f9f9;'>
        <h2 style='color:#333;'>Rezervimi u Konfirmua! &#9989;</h2>
        <p>Pershendetje <strong>$name</strong>,</p>
        <table style='width:100%;border-collapse:collapse;margin:20px 0;'>
            <tr style='background:#c9a84c;color:white;'><td style='padding:10px;'>Sherbimi</td><td style='padding:10px;'><strong>$service</strong></td></tr>
            <tr style='background:#fff;'><td style='padding:10px;'>Berberi</td><td style='padding:10px;'><strong>$barber</strong></td></tr>
            <tr style='background:#f5f5f5;'><td style='padding:10px;'>Data</td><td style='padding:10px;'><strong>$date</strong></td></tr>
            <tr style='background:#fff;'><td style='padding:10px;'>Ora</td><td style='padding:10px;'><strong>$time</strong></td></tr>
            <tr style='background:#f5f5f5;'><td style='padding:10px;'>Cmimi</td><td style='padding:10px;'><strong>$price</strong></td></tr>
        </table>
        <p>Ju presim me padurim!</p>
    </div>
    <div style='background:#1a1a1a;padding:15px;text-align:center;'><p style='color:#c9a84c;margin:0;'>King Cuts &bull; +355 69 123 4567</p></div>
    </body></html>";
    $payload = json_encode(array('from'=>'King Cuts <onboarding@resend.dev>','to'=>array($to),'subject'=>'Konfirmim Rezervimi - King Cuts','html'=>$html_body));
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer '.$resend_key,'Content-Type: application/json'));
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $res = json_decode($result, true);
    if($http_code === 200 || $http_code === 201) respond(array('success'=>true));
    else respond(array('error'=>'Email error: '.($res['message'] ?? $result)), 500);
}


// BARBER LOGIN
if($action === 'barber_login' && $method === 'POST'){
    $username = trim(isset($input['username']) ? $input['username'] : '');
    $password = trim(isset($input['password']) ? $input['password'] : '');
    if(!$username || !$password) respond(array('error'=>'Plotesoni fushat'), 400);
    $db = getDB();
    $stmt = $db->prepare("SELECT b.*, ba.password as pw FROM barber_accounts ba JOIN barbers b ON ba.barber_id=b.id WHERE ba.username=?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if($row && password_verify($password, $row['pw'])){
        respond(array('success'=>true,'barber_id'=>$row['id'],'barber_name'=>$row['name'],'token'=>base64_encode($username.':'.time())));
    }
    respond(array('error'=>'Username ose password i gabuar'), 401);
}

// GET BARBER ACCOUNTS
if($action === 'get_barber_accounts' && $method === 'GET'){
    $db = getDB();
    $rows = $db->query("SELECT ba.id, ba.username, b.name, b.id as barber_id FROM barber_accounts ba JOIN barbers b ON ba.barber_id=b.id ORDER BY b.name ASC")->fetch_all(MYSQLI_ASSOC);
    respond($rows);
}

// ADD/UPDATE BARBER ACCOUNT
if($action === 'save_barber_account' && $method === 'POST'){
    $db = getDB();
    $barber_id = intval(isset($input['barber_id']) ? $input['barber_id'] : 0);
    $username  = trim(isset($input['username']) ? $input['username'] : '');
    $password  = trim(isset($input['password']) ? $input['password'] : '');
    if(!$barber_id || !$username || !$password) respond(array('error'=>'Te dhena mungojne'), 400);
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO barber_accounts (barber_id, username, password) VALUES (?,?,?) ON DUPLICATE KEY UPDATE username=?, password=?");
    $stmt->bind_param('issss', $barber_id, $username, $hash, $username, $hash);
    if($stmt->execute()) respond(array('success'=>true));
    else respond(array('error'=>$db->error), 500);
}

// DELETE BARBER ACCOUNT
if($action === 'delete_barber_account' && $method === 'POST'){
    $db = getDB();
    $id = intval(isset($input['id']) ? $input['id'] : 0);
    if(!$id) respond(array('error'=>'ID mungon'), 400);
    $db->prepare("DELETE FROM barber_accounts WHERE id=?")->bind_param('i',$id) ;
    $stmt = $db->prepare("DELETE FROM barber_accounts WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    respond(array('success'=>true));
}

// GET REVIEWS
if($action === 'get_reviews' && $method === 'GET'){
    $db = getDB();
    $approved = isset($_GET['approved']) ? intval($_GET['approved']) : -1;
    $sql = "SELECT * FROM reviews";
    if($approved >= 0) $sql .= " WHERE approved=$approved";
    $sql .= " ORDER BY created_at DESC";
    respond($db->query($sql)->fetch_all(MYSQLI_ASSOC));
}

// ADD REVIEW
if($action === 'add_review' && $method === 'POST'){
    $db = getDB();
    $name    = isset($input['name'])    ? $input['name']    : '';
    $service = isset($input['service']) ? $input['service'] : '';
    $text    = isset($input['text'])    ? $input['text']    : '';
    $rating  = intval(isset($input['rating']) ? $input['rating'] : 0);
    if(!$name || !$text || !$rating) respond(array('error'=>'Te dhena mungojne'), 400);
    $stmt = $db->prepare("INSERT INTO reviews (name, service, text, rating, approved) VALUES (?,?,?,?,1)");
    $stmt->bind_param('sssi', $name, $service, $text, $rating);
    if($stmt->execute()) respond(array('success'=>true, 'id'=>$db->insert_id));
    else respond(array('error'=>$db->error), 500);
}

// APPROVE/DELETE REVIEW
if($action === 'update_review' && $method === 'POST'){
    $db = getDB();
    $id = intval(isset($input['id']) ? $input['id'] : 0);
    $approved = intval(isset($input['approved']) ? $input['approved'] : 1);
    if(!$id) respond(array('error'=>'ID mungon'), 400);
    $stmt = $db->prepare("UPDATE reviews SET approved=? WHERE id=?");
    $stmt->bind_param('ii', $approved, $id);
    $stmt->execute();
    respond(array('success'=>true));
}

if($action === 'delete_review' && $method === 'POST'){
    $db = getDB();
    $id = intval(isset($input['id']) ? $input['id'] : 0);
    if(!$id) respond(array('error'=>'ID mungon'), 400);
    $stmt = $db->prepare("DELETE FROM reviews WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    respond(array('success'=>true));
}


// GET BARBER STATS (per berberin e caktuar)
if($action === 'get_barber_stats' && $method === 'GET'){
    $db = getDB();
    $barber_name = isset($_GET['barber_name']) ? $_GET['barber_name'] : '';
    if(!$barber_name) respond(array('error'=>'Emri i berberit mungon'), 400);
    
    $total    = $db->query("SELECT COUNT(*) as c FROM bookings WHERE barber_name='".mysqli_real_escape_string($db,$barber_name)."'")->fetch_assoc()['c'];
    $today    = $db->query("SELECT COUNT(*) as c FROM bookings WHERE barber_name='".mysqli_real_escape_string($db,$barber_name)."' AND booking_date=CURDATE()")->fetch_assoc()['c'];
    $confirmed= $db->query("SELECT COUNT(*) as c FROM bookings WHERE barber_name='".mysqli_real_escape_string($db,$barber_name)."' AND status='confirmed'")->fetch_assoc()['c'];
    $completed= $db->query("SELECT COUNT(*) as c FROM bookings WHERE barber_name='".mysqli_real_escape_string($db,$barber_name)."' AND status='completed'")->fetch_assoc()['c'];
    $rev      = $db->query("SELECT SUM(CAST(REPLACE(REPLACE(price,' L',''),',','') AS DECIMAL(10,2))) as t FROM bookings WHERE barber_name='".mysqli_real_escape_string($db,$barber_name)."' AND status='completed' AND MONTH(booking_date)=MONTH(CURDATE())")->fetch_assoc();
    $weekly   = $db->query("SELECT booking_date, COUNT(*) as count FROM bookings WHERE barber_name='".mysqli_real_escape_string($db,$barber_name)."' AND booking_date>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) GROUP BY booking_date ORDER BY booking_date ASC")->fetch_all(MYSQLI_ASSOC);
    
    respond(array(
        'total_bookings'=>$total,
        'today_bookings'=>$today,
        'confirmed'=>$confirmed,
        'completed'=>$completed,
        'monthly_revenue'=>($rev?$rev['t']:0),
        'weekly'=>$weekly
    ));
}

// GET BOOKINGS FOR BARBER
if($action === 'get_barber_bookings' && $method === 'GET'){
    $db = getDB();
    $barber_name = isset($_GET['barber_name']) ? $_GET['barber_name'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    if(!$barber_name) respond(array('error'=>'Emri mungon'), 400);
    
    $bn = mysqli_real_escape_string($db, $barber_name);
    $sql = "SELECT * FROM bookings WHERE barber_name='$bn'";
    if($status && $status !== 'all') $sql .= " AND status='".mysqli_real_escape_string($db,$status)."'";
    $sql .= " ORDER BY booking_date ASC, booking_time ASC";
    respond($db->query($sql)->fetch_all(MYSQLI_ASSOC));
}

// GET SCHEDULES FOR BARBER
if($action === 'get_barber_schedules' && $method === 'GET'){
    $db = getDB();
    $barber_id = intval(isset($_GET['barber_id']) ? $_GET['barber_id'] : 0);
    if(!$barber_id) respond(array('error'=>'ID mungon'), 400);
    $rows = $db->query("SELECT * FROM schedules WHERE barber_id=$barber_id ORDER BY schedule_date ASC, blocked_time ASC")->fetch_all(MYSQLI_ASSOC);
    respond($rows);
}


// ANULIM REZERVIMI NGA KLIENTI (verifikon me telefon)
if($action === 'cancel_booking_client' && $method === 'POST'){
    $db = getDB();
    $id    = intval(isset($input['id'])    ? $input['id']    : 0);
    $phone = trim(isset($input['phone'])   ? $input['phone'] : '');
    if(!$id || !$phone) respond(array('error'=>'Te dhena mungojne'), 400);
    
    // Normalizoj numrin - heq te gjitha hapesirat dhe karakteret jo-numerike vec +
    $phone_clean = preg_replace('/[^0-9+]/', '', $phone);
    // Nese fillon me 0, zëvendëso me +355
    if(substr($phone_clean,0,1) === '0') $phone_clean = '+355'.substr($phone_clean,1);
    // Nese fillon me 355 pa +, shto +
    if(substr($phone_clean,0,3) === '355') $phone_clean = '+'.$phone_clean;
    
    // Merr booking dhe normalizoj numrin e ruajtur gjithashtu
    $stmt = $db->prepare("SELECT id, status, client_phone FROM bookings WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    
    if(!$row) respond(array('error'=>'Rezervimi nuk u gjet!'), 400);
    if($row['status'] === 'cancelled') respond(array('error'=>'Rezervimi eshte tashme i anuluar!'), 400);
    if($row['status'] === 'completed') respond(array('error'=>'Rezervimi eshte perfunduar dhe nuk mund te anulohet!'), 400);
    
    // Normalizoj numrin e ruajtur ne DB
    $db_phone = preg_replace('/[^0-9+]/', '', $row['client_phone']);
    if(substr($db_phone,0,1) === '0') $db_phone = '+355'.substr($db_phone,1);
    if(substr($db_phone,0,3) === '355') $db_phone = '+'.$db_phone;
    
    // Krahaso numrat e normalizuar
    if($phone_clean !== $db_phone){
        respond(array('error'=>'Numri i telefonit nuk perputhet! Shkruaj: '.$row['client_phone']), 400);
    }
    
    $stmt2 = $db->prepare("UPDATE bookings SET status='cancelled' WHERE id=?");
    $stmt2->bind_param('i', $id);
    $stmt2->execute();
    respond(array('success'=>true));
}

respond(array('error'=>'Action e panjohur: '.$action), 404);
?>