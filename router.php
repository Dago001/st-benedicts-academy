<?php
// router.php - development router for `php -S 127.0.0.1:8080 router.php`.
// Mirrors the Apache rules in .htaccess: extension-less URLs map to .php files,
// and direct .php URLs redirect (GET) to the clean address.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rawurldecode($uri);
$root = __DIR__;

// Never serve internals
if (preg_match('#^/(config|includes|sql|scripts|tests|logs|backups|storage)(/|$)#', $uri) || preg_match('#/\.#', $uri)) {
    http_response_code(403);
    exit('Forbidden');
}

$path = $root . $uri;
if ($uri !== '/' && is_file($path) && substr($uri, -4) !== '.php') {
    return false; // static file
}

// /something.php -> 301 /something (GET only)
if (substr($uri, -4) === '.php' && is_file($path)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $clean = substr($uri, 0, -4);
        if (substr($clean, -6) === '/index') $clean = substr($clean, 0, -5);
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: ' . ($clean === '' ? '/' : $clean) . ($qs !== '' ? '?' . $qs : ''), true, 301);
        exit;
    }
    $_SERVER['SCRIPT_NAME'] = $uri;
    require $path;
    return true;
}

$trim = rtrim($uri, '/');
if ($trim !== '' && is_file($root . $trim . '.php')) {
    $_SERVER['SCRIPT_NAME'] = $trim . '.php';
    $_SERVER['PHP_SELF'] = $trim . '.php';
    chdir(dirname($root . $trim . '.php'));
    require $root . $trim . '.php';
    return true;
}
if (is_dir($root . $trim) && is_file($root . $trim . '/index.php')) {
    $_SERVER['SCRIPT_NAME'] = $trim . '/index.php';
    $_SERVER['PHP_SELF'] = $trim . '/index.php';
    chdir($root . $trim);
    require $root . $trim . '/index.php';
    return true;
}
http_response_code(404);
if (is_file($root . '/error/404.php')) { require $root . '/error/404.php'; }
