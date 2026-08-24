FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    mariadb-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mysqli
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd
RUN docker-php-ext-install exif pcntl bcmath zip intl

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Konfig tuning: nilai bawaan dipakai kalau entrypoint tidak jalan
# (mis. image dipakai langsung tanpa entrypoint kustom).
COPY zzz-tuning.conf /usr/local/etc/php-fpm.d/zzz-tuning.conf

# Entrypoint merender ulang zzz-tuning.conf dari env saat start.
COPY entrypoint.sh /usr/local/bin/cbt-entrypoint.sh
RUN chmod +x /usr/local/bin/cbt-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/cbt-entrypoint.sh"]
CMD ["php-fpm"]

WORKDIR /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000
