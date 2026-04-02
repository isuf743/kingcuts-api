<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Nese eshte file fizik ekzistues, shfaqe normalisht
$file = __DIR__ . $uri;
if($uri !== '/' && file_exists($file) && is_file($file)){
    return false;
}

// Faqet kryesore - kalon normalisht
$pages = ['/', '/index.html', '/admin.html', '/booking.html', '/barber-panel.html', '/404.html'];
if(in_array($uri, $pages)){
    return false;
}

// API calls - kalon normalisht
if(strpos($uri, '/api.php') === 0 || strpos($uri, '/db_setup.php') === 0){
    return false;
}

// Cdo gje tjeter -> 404
http_response_code(404);
readfile(__DIR__ . '/404.html');
exit;