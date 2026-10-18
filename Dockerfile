FROM php:8.3-fpm

# ============================================================
# System dependencies and PHP extensions
# ============================================================
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libcurl4-openssl-dev \
        libpq-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        calendar \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# ============================================================
# Composer
# ============================================================
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ============================================================
# Application directory
# ============================================================
WORKDIR /var/www

# ============================================================
# Install production dependencies
# ============================================================
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts

# ============================================================
# Application source
# ============================================================
COPY . .

# ============================================================
# PHP configuration
# ============================================================
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/99-custom.ini

# ============================================================
# Laravel runtime directories and permissions
# ============================================================
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# ============================================================
# PHP-FPM
# Accessible internally through the Docker network.
# ============================================================
EXPOSE 9000

CMD ["php-fpm"]
