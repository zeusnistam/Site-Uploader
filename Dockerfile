FROM php:8.2-apache

# ── نصب وابستگی‌ها ──────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# ── افزونه‌های PHP (pdo و curl به‌صورت پیش‌فرض داخل ایمیج هستن) ──
RUN docker-php-ext-install pdo_mysql

# ── رفع خطای "More than one MPM loaded" ─────────────────────
# فقط prefork فعال بمونه (لازم برای mod_php)
RUN rm -f /etc/apache2/mods-enabled/mpm_event.* \
          /etc/apache2/mods-enabled/mpm_worker.* \
          /etc/apache2/mods-enabled/mpm_prefork.* && \
    a2enmod mpm_prefork

# ── تنظیم حجم آپلود (حداکثر ۲GB) ──────────────────────────
RUN echo "upload_max_filesize = 2048M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 2100M"       >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 512M"         >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time = 3600"   >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_input_time = 3600"       >> /usr/local/etc/php/conf.d/uploads.ini

# ── فعال‌کردن mod_rewrite و mod_headers (برای .htaccess) ─────
RUN a2enmod rewrite headers

# ── پورت داینامیک Railway (متغیر PORT) ──────────────────────
# اگه PORT ست نباشه، پیش‌فرض ۸۰
RUN echo 'export PORT="${PORT:-80}"' >> /etc/apache2/envvars && \
    sed -i 's/Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf && \
    sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf && \
    echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf && \
    a2enconf servername

# ── تنظیم Apache ────────────────────────────────────────────
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    Options -Indexes +FollowSymLinks' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' > /etc/apache2/conf-available/zeus.conf && \
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
RUN printf '%s\n' \
    'Options -Indexes' \
    'RewriteEngine On' \
    '' \
    'RewriteCond %{REQUEST_FILENAME} !-f' \
    'RewriteCond %{REQUEST_FILENAME} !-d' \
    'RewriteRule ^(.*)$ index.php [QSA,L]' \
    '' \
    '<IfModule mod_headers.c>' \
    '    Header always set X-Content-Type-Options nosniff' \
    '    Header always set X-Frame-Options SAMEORIGIN' \
    '    Header always set X-XSS-Protection "1; mode=block"' \
    '</IfModule>' > /var/www/html/.htaccess && \
    chown www-data:www-data /var/www/html/.htaccess

EXPOSE 80

CMD ["apache2-foreground"]
