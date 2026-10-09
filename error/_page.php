<?php
// Shared error page body ($code, $msg)
require_once __DIR__ . '/../config/security.php';
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo (int)$code; ?> - <?php echo e(SITE_NAME); ?></title>
<style>body{font-family:system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:16px;background:#f4f6f9;text-align:center;color:#223}
.box{background:#fff;padding:32px 24px;border-radius:12px;max-width:420px;box-shadow:0 8px 24px rgba(0,0,0,.08)}h1{font-size:4rem;margin:0;color:#002855}a{display:inline-block;margin-top:16px;padding:12px 22px;background:#c8102e;color:#fff;border-radius:8px;text-decoration:none}</style></head>
<body><div class="box"><h1><?php echo (int)$code; ?></h1><p><?php echo e($msg); ?></p><a href="<?php echo e(BASE_URL); ?>/">Back to home</a></div></body></html>
