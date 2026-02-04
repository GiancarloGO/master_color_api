#!/bin/bash
set -e

echo "🚀 Starting Laravel application with Nginx + PHP-FPM..."

# Configurar permisos
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 755 /var/www/html/storage /var/www/html/bootstrap/cache

echo "✅ Permissions configured"

# Optimizar Laravel
echo "🔧 Optimizing Laravel..."
php artisan config:cache
php artisan route:cache
php artisan view:clear
echo "✅ Laravel optimized"

# Iniciar PHP-FPM en background
echo "🎯 Starting PHP-FPM..."
php-fpm -D

# Iniciar Nginx en foreground
echo "🌐 Starting Nginx..."
nginx -g 'daemon off;'
