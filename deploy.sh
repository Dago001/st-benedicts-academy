#!/bin/bash
set -e

DEPLOYPATH="/home/stbenedi/public_html"
mkdir -p "$DEPLOYPATH" "$DEPLOYPATH/uploads" "$DEPLOYPATH/storage/applications" "$DEPLOYPATH/logs"

# Deploy files to public_html, excluding repo metadata, tests, and preserving server-side data
if command -v rsync >/dev/null 2>&1; then
    rsync -rlptD \
      --exclude='.git' \
      --exclude='.github' \
      --exclude='tests' \
      --exclude='docs' \
      --exclude='uploads' \
      --exclude='storage' \
      --exclude='logs' \
      --exclude='backups' \
      --exclude='config/local.php' \
      ./ "$DEPLOYPATH/"
else
    # Fallback to tar copy if rsync is not installed in shared hosting environment
    tar --exclude='./.git' --exclude='./.github' --exclude='./tests' --exclude='./docs' --exclude='./uploads' --exclude='./storage' --exclude='./logs' --exclude='./backups' --exclude='./config/local.php' -cf - . | (cd "$DEPLOYPATH" && tar -xf -)
fi

# Ensure uploads protection files exist
[ -f uploads/.htaccess ] && cp -n uploads/.htaccess "$DEPLOYPATH/uploads/" 2>/dev/null || true
[ -f uploads/index.html ] && cp -n uploads/index.html "$DEPLOYPATH/uploads/" 2>/dev/null || true

# Find available PHP CLI binary
PHP_BIN=""
for candidate in php /usr/bin/php /usr/local/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php; do
    if command -v "$candidate" >/dev/null 2>&1; then
        PHP_BIN="$candidate"
        break
    fi
done

if [ -n "$PHP_BIN" ] && [ -f "$DEPLOYPATH/scripts/migrate.php" ]; then
    echo "Running database migration with $PHP_BIN..."
    "$PHP_BIN" "$DEPLOYPATH/scripts/migrate.php" || true
fi

echo "Deployment complete."
