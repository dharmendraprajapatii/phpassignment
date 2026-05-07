FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    libzip-dev \
    zip \
    libicu-dev \
    libonig-dev \
    && docker-php-ext-install pdo pdo_mysql zip mbstring bcmath intl

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

ENV COMPOSER_ALLOW_SUPERUSER=1

# Copy composer files first
COPY composer.json composer.lock ./

# Install dependencies (skip scripts)
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Copy full project
COPY . .

# Run Laravel scripts AFTER files exist
RUN php artisan package:discover --ansi || true

# Fix permissions
RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["sh", "docker/entrypoint.sh"]