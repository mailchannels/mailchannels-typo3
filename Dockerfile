ARG PHP_VERSION=8.4.26
FROM php:${PHP_VERSION}-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev libonig-dev libpng-dev libxml2-dev \
    && docker-php-ext-install intl mbstring zip gd pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.9.8 /usr/bin/composer /usr/local/bin/composer
