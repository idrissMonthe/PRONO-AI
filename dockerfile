
# ==========================================
# ETAPE 1 : Compilation des ressources frontend
# ==========================================
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build


# ==========================================
# ETAPE 2 : Application Laravel
# ==========================================
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libonig-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring bcmath exif pcntl gd zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

# Configuration Apache pour Laravel
RUN a2enmod rewrite \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' \
        /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/' \
        /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Installation des dépendances PHP
COPY composer.json composer.lock ./

RUN composer config --global process-timeout 900 \
    && composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts

# Copie du projet
COPY . .

# Récupération des fichiers frontend compilés
COPY --from=frontend /app/public/build ./public/build

# Préparation de Laravel
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 80
