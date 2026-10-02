#!/bin/bash
set -e

# Render passes PORT dynamically (default 10000)
PORT="${PORT:-80}"

echo "==> Configuring Apache to listen on port ${PORT}..."
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Ensure required upload directories exist and permissions are set
echo "==> Ensuring upload storage directories exist..."
mkdir -p /var/www/html/uploads \
         /var/www/html/private_uploads/lms_materials \
         /var/www/html/private_uploads/lms_assignments \
         /var/www/html/private_uploads/lms_assignment_files \
         /var/www/html/private_uploads/lms_submissions \
         /var/www/html/private_uploads/lms_lessons

chown -R www-data:www-data /var/www/html/uploads /var/www/html/private_uploads
chmod -R 775 /var/www/html/uploads /var/www/html/private_uploads

# Auto-initialize database on startup if DB_AUTO_INIT=true
if [ "${DB_AUTO_INIT}" = "true" ]; then
    echo "==> DB_AUTO_INIT is enabled. Running database setup..."
    php /var/www/html/database/setup_cloud_db.php || echo "[!] Database setup encountered an error. Continuing startup..."
fi

echo "==> Starting Apache in foreground..."
exec apache2-foreground
