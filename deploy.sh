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

# Check Apache mod_rewrite
if command -v a2enmod &> /dev/null; then
    if apachectl -M 2>/dev/null | grep -q rewrite; then
        echo "mod_rewrite: OK"
    else
        echo "WARNING: mod_rewrite not enabled. Run: sudo a2enmod rewrite && sudo systemctl restart apache2"
    fi
elif command -v httpd &> /dev/null; then
    if httpd -M 2>/dev/null | grep -q rewrite; then
        echo "mod_rewrite: OK"
    else
        echo "WARNING: mod_rewrite not enabled (check your Apache config)"
    fi
else
    echo "Apache not detected — ensure mod_rewrite is enabled if using Apache"
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
chmod 644 index.php app.js zbar-wasm.js zbar.wasm reset-db.php config.php .htaccess favicon.ico favicon-*.png apple-touch-icon.png android-chrome-*.png site.webmanifest 2>/dev/null
chmod 755 . 2>/dev/null
if [ ! -f fridgestare.db ]; then
    touch fridgestare.db
fi
chmod 666 fridgestare.db 2>/dev/null

echo ""
echo "Permissions set."
echo "Health check: GET /api/health"
