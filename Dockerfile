FROM php:8.2-apache

# ── نصب وابستگی‌ها ──────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# ── افزونه‌های PHP ──────────────────────────────────────────
RUN docker-php-ext-install pdo pdo_mysql curl

# ── تنظیم حجم آپلود (حداکثر ۲GB) ──────────────────────────
RUN echo "upload_max_filesize = 2048M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 2100M"       >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 512M"         >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time = 3600"   >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_input_time = 3600"       >> /usr/local/etc/php/conf.d/uploads.ini

# ── فعال‌کردن mod_rewrite ───────────────────────────────────
RUN a2enmod rewrite

# ── تنظیم Apache ────────────────────────────────────────────
RUN echo '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/zeus.conf && \
    a2enconf zeus

# ── کپی کردن فایل‌های سایت ──────────────────────────────────
COPY config.php  /var/www/html/config.php
COPY index.php   /var/www/html/index.php
COPY bot.php     /var/www/html/bot.php

# ── ساخت پوشه uploads و تنظیم دسترسی ───────────────────────
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 775 /var/www/html/uploads

# ── .htaccess اصلی ──────────────────────────────────────────
RUN echo 'Options -Indexes\n\
RewriteEngine On\n\
\n\
# همه درخواست‌ها به index.php\n\
RewriteCond %{REQUEST_FILENAME} !-f\n\
RewriteCond %{REQUEST_FILENAME} !-d\n\
RewriteRule ^(.*)$ index.php [QSA,L]\n\
\n\
# Security headers\n\
Header always set X-Content-Type-Options nosniff\n\
Header always set X-Frame-Options SAMEORIGIN\n\
Header always set X-XSS-Protection "1; mode=block"' > /var/www/html/.htaccess

EXPOSE 80

CMD ["apache2-foreground"]
