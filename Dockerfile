FROM node:24-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY index.html vite.config.js ./
COPY src ./src
# Optional public branding. Vite embeds these values in the browser bundle.
ARG VITE_INVOICE_SELLER_NAME
ARG VITE_INVOICE_SELLER_ADDRESS
RUN npm run build

FROM php:8.4-apache-bookworm AS php-runtime
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql opcache \
    && a2enmod rewrite \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/orderdesk.ini /usr/local/etc/php/conf.d/99-orderdesk.ini
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/apache-runtime.conf /etc/apache2/conf-available/orderdesk.conf
RUN a2enconf orderdesk
WORKDIR /app/backend

FROM php-runtime AS backend
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY backend/ ./
RUN mkdir -p bootstrap/cache storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && composer check-platform-reqs --no-dev

FROM php-runtime AS production
ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=mysql \
    SESSION_DRIVER=database \
    SESSION_ENCRYPT=true \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=database \
    LOG_CHANNEL=stderr \
    QUEUE_CONNECTION=sync
COPY --from=backend /app/backend /app/backend
COPY --from=frontend /app/backend/public/dist /app/backend/public/dist
COPY --chmod=0755 deploy/start.sh /usr/local/bin/orderdesk-start
COPY deploy/healthcheck.php /usr/local/lib/orderdesk-healthcheck.php
RUN chown -R www-data:www-data bootstrap/cache storage \
    && chmod 0700 storage/app/private
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD ["php", "/usr/local/lib/orderdesk-healthcheck.php"]
CMD ["orderdesk-start"]
