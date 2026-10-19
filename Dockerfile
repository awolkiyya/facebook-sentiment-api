# syntax=docker/dockerfile:1

FROM php:8.3-fpm

ENV APP_ENV=production \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

WORKDIR /var/www

# ------------------------------------------------------------
# System packages and PHP extensions
# ------------------------------------------------------------
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
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
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# ------------------------------------------------------------
# Composer
# ------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Install production dependencies before copying application code
# to improve Docker layer caching.
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts

# ------------------------------------------------------------
# Application source
# ------------------------------------------------------------
COPY . .

# ------------------------------------------------------------
# PHP production configuration
# ------------------------------------------------------------
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/99-custom.ini

# ------------------------------------------------------------
# Laravel writable directories
# ------------------------------------------------------------
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && php -v \
    && php -m

# PHP-FPM is accessible only through the internal Docker network.
EXPOSE 9000

CMD ["php-fpm"]
