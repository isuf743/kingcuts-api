<?php

function logError($message, $context = []){
    $logFile = __DIR__ . '/logs/app.log';

    // Create logs folder if not exists
    if(!file_exists(__DIR__ . '/logs')){
        mkdir(__DIR__ . '/logs', 0777, true);
    }

    $date = date('Y-m-d H:i:s');

    $entry = [
        'time' => $date,
        'message' => $message,
        'context' => $context
    ];

    file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND
    );
}