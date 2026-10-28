<?php
// Router for PHP's built-in dev server, mirroring public/.htaccess:
//   php -S 127.0.0.1:8080 -t public dev/router.php
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = $_SERVER['DOCUMENT_ROOT'];

if ($path === '/welcome' || $path === '/welcome/') {
    $q = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /' . ($q !== '' ? "?$q" : ''), true, 301);
    return true;
}
if (str_contains($path, '..')) { http_response_code(400); return true; }
// Old WordPress image URLs -> new asset folder (same as .htaccess).
if (preg_match('#^/wp-content/uploads/(?:\d{4}/\d{2}/)?([^/]+)$#', $path, $m) && is_file($_SERVER['DOCUMENT_ROOT'] . '/assets/img/' . $m[1])) {
    header('Location: /assets/img/' . $m[1], true, 301);
    return true;
}
if ($path === '/api/_init.php' || preg_match('#/\.#', $path)) { http_response_code(403); return true; }

$file = $root . $path;
if (is_file($file)) {
    if (str_ends_with($file, '.php')) return false;   // let the server execute it
    return false;                                       // serve static file
}
if (is_dir($file)) {
    if (!str_ends_with($path, '/')) { header('Location: ' . $path . '/', true, 301); return true; }
    if (is_file($file . 'index.html')) return false;
    if (is_file($file . 'index.php')) { require $file . 'index.php'; return true; }
}
http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
readfile($root . '/404.html');
return true;
