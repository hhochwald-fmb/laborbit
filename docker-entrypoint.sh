#!/bin/bash
set -e

CONFIG="/var/www/html/config.php"

# Auto-generate config.php from environment variables if it doesn't exist yet.
# Set DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PREFIX in docker-compose.yml or via -e flags.
if [ ! -f "$CONFIG" ] && [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ]; then
    cat > "$CONFIG" <<PHP
<?php
define('DB_HOST', '${DB_HOST}');
define('DB_USER', '${DB_USER:-root}');
define('DB_PASS', '${DB_PASS:-}');
define('DB_NAME', '${DB_NAME}');
define('DB_PREFIX', '${DB_PREFIX:-}');

// define('EMERGENCY_ADMIN_PASS', 'DeinNeuesPasswort123!');
PHP
    chown www-data:www-data "$CONFIG"
    echo "[laborbit] config.php generated from environment variables."
    echo "[laborbit] Visit /setup.php to complete the installation (create tables + admin user)."
fi

exec "$@"
