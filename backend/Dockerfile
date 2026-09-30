# syntax=docker/dockerfile:1

ARG APP_URL=https://foreign.nvnhan0810.com

# -----------------------------------------------------------------------------
# PHP dependencies
# -----------------------------------------------------------------------------
FROM php:8.4-cli-bookworm AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

ARG APP_URL
WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_URL=${APP_URL}

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    && composer dump-autoload --optimize \
    && php artisan package:discover --ansi

# -----------------------------------------------------------------------------
# Frontend assets (Vite / Vue / Tailwind)
# -----------------------------------------------------------------------------
FROM node:22-bookworm AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js jsconfig.json ./
COPY resources ./resources
COPY public/css ./public/css

RUN npm run build

# -----------------------------------------------------------------------------
# Production runtime (nginx + php-fpm).
# Default CMD = web (config:cache once at start, then supervisord).
# Queue / scheduler / migrate: K8s `args` replaces this entire CMD (they never hit config:cache).
# -----------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS runtime

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC

RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    curl \
    ca-certificates \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libpq-dev \
    libonig-dev \
    libxml2-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
    gd \
    pdo_pgsql \
    zip \
    bcmath \
    opcache \
    mbstring \
    pcntl \
    xml \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && rm -f /etc/nginx/sites-enabled/default.bak

COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

COPY --from=vendor /app/app ./app
COPY --from=vendor /app/src ./src
COPY --from=vendor /app/bootstrap ./bootstrap
COPY --from=vendor /app/config ./config
COPY --from=vendor /app/database ./database
COPY --from=vendor /app/routes ./routes
COPY --from=vendor /app/resources ./resources
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /app/artisan ./artisan
COPY --from=vendor /app/composer.json ./composer.json
COPY --from=vendor /app/composer.lock ./composer.lock
COPY public ./public
COPY --from=frontend /app/public/build ./public/build

# dash (Docker /bin/sh) has no brace expansion — list dirs explicitly.
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# route/view caches are env-independent enough to bake at build (dummy APP_KEY only for this RUN).
# Do NOT config:cache here — K8s injects real secrets at runtime; baking would freeze wrong env.
ARG APP_URL
RUN APP_ENV=production \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_URL=${APP_URL} \
    php artisan route:cache \
 && APP_ENV=production \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_URL=${APP_URL} \
    php artisan view:cache

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

# Web default CMD. Queue / scheduler / migrate override via K8s `args` (replaces this whole CMD).
# config:cache only runs on web pod start (secrets already injected); one-shot jobs never see it.
CMD ["sh", "-c", "php artisan config:cache && exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf"]
