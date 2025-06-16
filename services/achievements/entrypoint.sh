#!/bin/sh

echo "🔧 Fixing Laravel storage and cache permissions..."

mkdir -p storage/framework/{cache,data,sessions,views,testing}
mkdir -p bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "✅ Permissions set."

# Start PHP-FPM
exec php-fpm
