FROM php:8.4-cli

# Install dependencies
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

# Copy FULL project first
COPY . .

# Install dependencies
RUN composer install --no-interaction --prefer-dist

# Fix permissions
RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["sh", "docker/entrypoint.sh"]