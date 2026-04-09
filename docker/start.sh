#!/bin/sh

echo "Starting unified Laravel container..."

# Set working directory
cd /var/www/html

# Ensure proper permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Create necessary directories with proper permissions
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/app
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

# Install/update composer dependencies if composer.json exists
if [ -f "/var/www/html/composer.json" ]; then
    echo "Installing/updating composer dependencies..."
    composer install --no-dev --optimize-autoloader 2>/dev/null || true
fi

# Run Laravel optimizations if artisan exists
if [ -f "/var/www/html/artisan" ]; then
    echo "Running Laravel optimizations..."
    php artisan config:cache 2>/dev/null || true
    php artisan route:cache 2>/dev/null || true  
    php artisan view:cache 2>/dev/null || true
    php artisan storage:link 2>/dev/null || true
fi

echo "Authentication is handled by Laravel middleware based on AUTH environment variable"
echo "Current AUTH setting: ${AUTH:-none}"

# Create exec session directory for terminal FIFO pipes
mkdir -p /tmp/exec-sessions
chmod 777 /tmp/exec-sessions

# Create log directories for supervisor
mkdir -p /var/log/supervisor

# Check if supervisord config exists
if [ ! -f "/etc/supervisor/conf.d/supervisord.conf" ]; then
    echo "ERROR: supervisord.conf not found!"
    exit 1
fi

# Ensure logs directory exists and has correct permissions
mkdir -p /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage/logs
chmod -R 775 /var/www/html/storage/logs

# Touch the laravel.log file to ensure it exists with correct permissions
touch /var/www/html/storage/logs/laravel.log
chown www-data:www-data /var/www/html/storage/logs/laravel.log
chmod 664 /var/www/html/storage/logs/laravel.log

# Start supervisor which will manage nginx, php-fpm, and heartbeat
echo "Starting supervisor with all services..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
