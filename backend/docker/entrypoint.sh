#!/bin/sh
set -e

cd /var/www/html

mkdir -p \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  storage/app/public \
  bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

if [ "$APP_ENV" != "production" ]; then
  # Host-mounted dev trees need OPcache to notice edited PHP files (routes, controllers, …).
  if [ -f /usr/local/etc/php/conf.d/99-opcache.ini ]; then
    sed -i 's/opcache.validate_timestamps=0/opcache.validate_timestamps=1/' /usr/local/etc/php/conf.d/99-opcache.ini
  fi
fi

if [ "$APP_ENV" = "production" ]; then
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
fi

exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
