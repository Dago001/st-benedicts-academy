#!/usr/bin/env python3
"""Mechanical clean-ups shared by the admin pages (idempotent)."""
import re, sys
for p in sys.argv[1:]:
    s = open(p, encoding='utf8').read()
    o = s
    s = re.sub(r"// Check if header exists\n\$headerPath = __DIR__ \. '/\.\./includes/header\.php';\nif \(!file_exists\(\$headerPath\)\) \{\n    die\([^\n]*\n\}\ninclude \$headerPath;\n",
               "include __DIR__ . '/../includes/header.php';\n", s)
    s = re.sub(r"// Check if header file exists before including\n\$headerPath = dirname\(__DIR__\) \. '/includes/header\.php';\nif \(!file_exists\(\$headerPath\)\) \{\n    die\([^\n]*\n\}\ninclude \$headerPath;\n",
               "include __DIR__ . '/../includes/header.php';\n", s)
    s = s.replace("$message = '';\n$messageType = '';\n", "[$message, $messageType] = flash_get();\n", 1)
    s = s.replace("$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;", "$page = page_param('p');")
    s = s.replace("$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;", "$page = page_param('page');")
    s = s.replace("$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;", "$page = page_param('page');")
    s = s.replace("htmlspecialchars($e->getMessage())", "htmlspecialchars(DEBUG_MODE ? $e->getMessage() : 'Please try again later.')")
    # drop references to assets that do not exist (header also guards, this keeps pages tidy)
    s = s.replace("'dataTables.css', ", "").replace("['dataTables.css']", "[]").replace(", 'dataTables.js'", "").replace("'dataTables.js'", "")
    if s != o:
        open(p, 'w', encoding='utf8').write(s)
        print('patched', p)
