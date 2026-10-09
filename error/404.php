<?php
require_once __DIR__ . '/../config/config.php';
http_response_code(404);
$code = 404; $msg = 'The page you are looking for could not be found.';
require __DIR__ . '/_page.php';
