<?php
// Calls every public read-style method of the model classes with plausible arguments;
// any SQL error is logged by Database::query and reported here.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/security.php';
$_SESSION['user_id'] = 1; $_SESSION['user_role'] = 'admin';
$bad = 0; $calls = 0;
foreach (['Attendance', 'AuditLog', 'ClassModel', 'Fee', 'Result', 'Student', 'Teacher', 'User'] as $cls) {
    $obj = new $cls();
    foreach ((new ReflectionClass($cls))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->isConstructor() || $m->isStatic() || preg_match('/^(create|add|delete|remove|update|save|record|approve|promote|clear|bulk|import|generate|mark|set|assign|insert|process|void|cancel|refund|transfer)/i', $m->getName())) continue;
        $args = [];
        foreach ($m->getParameters() as $p) {
            if ($p->isDefaultValueAvailable()) { $args[] = $p->getDefaultValue(); continue; }
            $n = strtolower($p->getName());
            $args[] = (strpos($n, 'year') !== false) ? '2024-2025' : ((strpos($n, 'term') !== false) ? 'Term 1' : ((strpos($n, 'date') !== false) ? date('Y-m-d') : ((strpos($n, 'month') !== false) ? date('Y-m') : ((strpos($n, 'filter') !== false || strpos($n, 'data') !== false || strpos($n, 'ids') !== false) ? [] : 1))));
        }
        $calls++;
        try { $m->invokeArgs($obj, $args); }
        catch (Throwable $e) { $bad++; echo "$cls::{$m->getName()} -> " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
    }
}
echo "$calls calls, $bad failures\n";
