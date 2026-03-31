<?php

require_once 'settings.php';

function logError($message, $context = [], $level = 'error'){
    
    // ---- SAVE TO DB ----
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

        if(!$conn->connect_error){
            $stmt = $conn->prepare("INSERT INTO logs (level, message, context) VALUES (?, ?, ?)");
            $ctx = json_encode($context, JSON_UNESCAPED_UNICODE);

            $stmt->bind_param('sss', $level, $message, $ctx);
            $stmt->execute();
        }

    } catch(Exception $e){
        // fallback to file if DB fails
        fileLog("DB_LOG_FAIL", [
            'original_message' => $message,
            'error' => $e->getMessage()
        ]);
    }

    // ---- ALSO SAVE TO FILE (backup) ----
    fileLog($message, $context);
}

function fileLog($message, $context = []){
    $logFile = __DIR__ . '/logs/app.log';

    if(!file_exists(__DIR__ . '/logs')){
        mkdir(__DIR__ . '/logs', 0777, true);
    }

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context
    ];

    file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND
    );
}