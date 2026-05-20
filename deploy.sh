#!/bin/bash
# FridgeStare Quick Deploy Script
# Run on your server after uploading files

echo "FridgeStare Deploy Script"
echo "======================="

# Check PHP version
PHP_VER=$(php -r "echo PHP_VERSION;")
echo "PHP version: $PHP_VER"

# Check SQLite support
php -r "new PDO('sqlite::memory:');" 2>/dev/null
if [ $? -eq 0 ]; then
    echo "SQLite: OK"
else
    echo "WARNING: PDO SQLite not available. Install php-sqlite3."
fi

# Check zbarimg
if command -v zbarimg &> /dev/null; then
    echo "zbarimg: OK (server-side photo fallback available)"
else
    echo "zbarimg: NOT FOUND (server-side photo fallback disabled)"
    echo "  Install with: sudo apt-get install -y zbar-tools  (Debian/Ubuntu)"
    echo "           or: sudo yum install -y zbar             (RHEL/CentOS)"
fi

# Set permissions
chmod 644 *.php *.js *.html *.css *.png *.ico *.webmanifest 2>/dev/null
chmod 644 .htaccess 2>/dev/null
chmod 666 groscan.db 2>/dev/null || true

echo ""
echo "Permissions set."
echo "Health check: GET /api/health"
