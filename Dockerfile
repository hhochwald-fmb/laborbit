FROM php:8.2-apache

# System dependencies for GD (JPEG/PNG support)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd exif zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# PHP upload / memory limits
RUN { \
        echo 'upload_max_filesize = 500M'; \
        echo 'post_max_size = 512M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 300'; \
        echo 'max_input_time = 300'; \
    } > /usr/local/etc/php/conf.d/laborbit.ini

# Allow .htaccess overrides in the document root
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

COPY . /var/www/html/

# Persistent upload directories
RUN mkdir -p \
        /var/www/html/uploads/photos \
        /var/www/html/uploads/videos \
        /var/www/html/uploads/pdfs \
        /var/www/html/uploads/measurements \
        /var/www/html/uploads/devices \
        /var/www/html/uploads/risk_assessments \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
