FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    unzip \
    bash \
    postgresql-dev \
    redis \
    pecl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo_pgsql \
        pgsql \
        intl \
        mbstring \
        xml \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create system user
RUN adduser -D -g '' www-data

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Configure PHP-FPM
RUN echo "pm = dynamic" >> /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo "pm.max_children = 50" >> /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo "pm.start_servers = 5" >> /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo "pm.min_spare_servers = 5" >> /usr/local/etc/php-fpm.d/zzz-custom.conf && \
    echo "pm.max_spare_servers = 35" >> /usr/local/etc/php-fpm.d/zzz-custom.conf

# Expose port 9000 for PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]
