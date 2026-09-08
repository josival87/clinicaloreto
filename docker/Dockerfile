FROM node:22-alpine AS frontend
WORKDIR /build/frontend
RUN npm install -g pnpm@11.19.0
COPY frontend/package.json frontend/pnpm-lock.yaml frontend/pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY frontend/ ./
RUN pnpm build

FROM php:8.5-fpm-alpine AS php-base
RUN apk add --no-cache libpq icu-libs libzip oniguruma libpng libjpeg-turbo freetype && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev oniguruma-dev libpng-dev libjpeg-turbo-dev freetype-dev && docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j4 pdo_pgsql intl mbstring zip bcmath pcntl gd && apk del .build-deps
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader
COPY backend/ ./
COPY --from=frontend /build/backend/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]

FROM nginx:1.28-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=php-base /var/www/html/public /var/www/html/public
