<?php
require_once __DIR__ . '/../config/config.php';
http_response_code(403);
$code = 403; $msg = 'You do not have permission to view this page.';
require __DIR__ . '/_page.php';
