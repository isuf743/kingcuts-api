<?php

require 'settings.php';
require 'logger.php';
require 'mail.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS'){
    http_response_code(200);
    exit;
}

// ---------------- HELPERS ----------------

function respond($data, $code=200){
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getDB(){
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

    if($conn->connect_error){
        logError("DB_CONNECTION_ERROR", ['error'=>$conn->connect_error]);
        respond(['error'=>'Database error'], 500);
    }

    $conn->set_charset('utf8');
    return $conn;
}

// ---------------- AUTH ----------------

function generateToken($username){
    $payload = base64_encode(json_encode([
        'user'=>$username,
        'exp'=>time()+86400
    ]));

    $sig = hash_hmac('sha256', $payload, APP_SECRET);
    return $payload.'.'.$sig;
}

function verifyToken($token){
    $parts = explode('.', $token);
    if(count($parts)!==2) return false;

    [$payload,$sig] = $parts;
    $valid = hash_hmac('sha256', $payload, APP_SECRET);

    if(!hash_equals($valid,$sig)) return false;

    $data = json_decode(base64_decode($payload), true);
    if(!$data || $data['exp'] < time()) return false;

    return $data;
}

function requireAuth(){
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? '';

    if(!$token || !verifyToken($token)){
        respond(['error'=>'Unauthorized'], 401);
    }
}

// ---------------- RATE LIMIT ----------------

function rateLimit($key,$limit=5,$sec=60){
    $file = __DIR__."/rate_$key.json";
    $data = file_exists($file)?json_decode(file_get_contents($file),true):[];

    $now=time();
    $data = array_filter($data, fn($t)=>$t>$now-$sec);

    if(count($data)>=$limit){
        respond(['error'=>'Too many requests'],429);
    }

    $data[]=$now;
    file_put_contents($file,json_encode($data));
}

// ---------------- INPUT ----------------

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$input = json_decode(file_get_contents('php://input'), true);
if(!is_array($input)) $input = [];

// SAFE LOG
$safeInput = $input;
unset($safeInput['password']);

logError("REQUEST", [
    'action'=>$action,
    'method'=>$method,
    'input'=>$safeInput
], 'info');

// ---------------- ADMIN LOGIN ----------------

if($action==='admin_login' && $method==='POST'){
    $db=getDB();

    $u=$input['username']??'';
    $p=$input['password']??'';

    $stmt=$db->prepare("SELECT * FROM admins WHERE username=?");
    $stmt->bind_param('s',$u);
    $stmt->execute();

    $a=$stmt->get_result()->fetch_assoc();

    if($a && password_verify($p,$a['password'])){
        respond([
            'success'=>true,
            'token'=>generateToken($u)
        ]);
    }

    respond(['error'=>'Login failed'],401);
}

// ---------------- ADD BOOKING ----------------

if($action==='add_booking' && $method==='POST'){

    rateLimit($_SERVER['REMOTE_ADDR']);

    $db=getDB();

    $name=$input['client_name']??'';
    $phone=$input['client_phone']??'';
    $email=$input['client_email']??'';
    $barber=$input['barber_name']??'';
    $service=$input['service_name']??'';
    $price=$input['price']??'';
    $date=$input['booking_date']??'';
    $time=$input['booking_time']??'';

    if(!$name||!$phone||!$date||!$time){
        respond(['error'=>'Missing fields'],400);
    }

    $stmt=$db->prepare("
        INSERT INTO bookings 
        (client_name,client_phone,client_email,barber_name,service_name,price,booking_date,booking_time,status)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");

    $status='confirmed';

    $stmt->bind_param('sssssssss',
        $name,$phone,$email,$barber,$service,$price,$date,$time,$status
    );

    if(!$stmt->execute()){
        logError("BOOKING_INSERT_FAIL", ['error'=>$stmt->error]);
        respond(['error'=>'DB error'],500);
    }

    $id=$db->insert_id;

    // EMAIL QUEUE
    queueEmail($email,"Rezervimi juaj","Rezervimi u konfirmua");
    queueEmail(ADMIN_EMAIL,"Rezervim i ri","Klient: $name");

    logError("BOOKING_CREATED",$input,'info');

    respond(['success'=>true,'id'=>$id]);
}

// ---------------- GET BOOKINGS ----------------

if($action==='get_bookings'){
    requireAuth();

    $db=getDB();
    $r=$db->query("SELECT * FROM bookings ORDER BY id DESC");

    respond($r->fetch_all(MYSQLI_ASSOC));
}

// ---------------- DELETE ----------------

if($action==='delete_booking'){
    requireAuth();

    $db=getDB();
    $id=intval($input['id']??0);

    $stmt=$db->prepare("DELETE FROM bookings WHERE id=?");
    $stmt->bind_param('i',$id);
    $stmt->execute();

    respond(['success'=>true]);
}

// ---------------- LOGS ----------------

if($action==='get_logs'){
    requireAuth();

    $db=getDB();

    $level=$_GET['level']??'';

    $sql="SELECT * FROM logs";
    if($level){
        $sql.=" WHERE level='$level'";
    }
    $sql.=" ORDER BY id DESC LIMIT 100";

    $res=$db->query($sql);

    $logs=[];
    while($r=$res->fetch_assoc()){
        $r['context']=json_decode($r['context'],true);
        $logs[]=$r;
    }

    respond($logs);
}

// ---------------- DEFAULT ----------------

respond(['error'=>'Invalid action'],404);