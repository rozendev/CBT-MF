FROM php:8.5-apache

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

# Show which extensions are already loaded (for debugging)
RUN php -m

# Install PHP extensions (split into groups to isolate failures)
# Group 1: Database
RUN docker-php-ext-install pdo_mysql mysqli

# Group 2: Image processing
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# Group 3: Common extensions
RUN docker-php-ext-install \
    exif \
    pcntl \
    bcmath \
    zip \
    intl

# Install Redis extension from source (pecl not yet compatible with PHP 8.5)
RUN mkdir -p /usr/src/php/ext/redis \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/develop.tar.gz \
       | tar xz -C /usr/src/php/ext/redis --strip-components=1 \
    && docker-php-ext-install redis

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
