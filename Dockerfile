FROM php:8.2-apache

# Enable Apache modules required by .htaccess (rewrite rules, cache/expires headers, compression)
RUN a2enmod rewrite headers expires deflate

# Install PostgreSQL PDO extension
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure Apache document root
ENV APACHE_DOCUMENT_ROOT /var/www/html

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN echo '<Directory /var/www/html>\n\tAllowOverride All\n\tOptions -Indexes\n</Directory>' \
    > /etc/apache2/conf-available/tkmi.conf \
    && a2enconf tkmi

# Copy project files
COPY . /var/www/html/

# Make entrypoint executable
RUN chmod +x /var/www/html/entrypoint.sh

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/uploads/players /var/www/html/uploads/sponsors \
    && chmod -R 775 /var/www/html/uploads

ENTRYPOINT ["/var/www/html/entrypoint.sh"]
