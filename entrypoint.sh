#!/bin/bash
set -e

# Configure dynamic port for Railway / Cloud hosts
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Ensure uploads directories exist with proper write permissions
mkdir -p /var/www/html/uploads/players /var/www/html/uploads/sponsors
chown -R www-data:www-data /var/www/html/uploads
chmod -R 775 /var/www/html/uploads

exec apache2-foreground
