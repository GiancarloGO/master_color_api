#!/bin/bash
set -e

echo "🚀 Starting Laravel application..."

# Ensure storage directories exist and have correct permissions
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/framework/{cache,sessions,views}
mkdir -p /var/www/html/bootstrap/cache

# Set correct permissions
chown -R www-data:www-data /var/www/html/storage
chown -R www-data:www-data /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache

echo "✅ Storage directories configured"

# Clear and cache configuration
echo "🔧 Optimizing Laravel..."
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:clear

echo "✅ Laravel optimized"

# Create supervisor log directory
mkdir -p /var/log/supervisor

echo "🎯 Starting Supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
