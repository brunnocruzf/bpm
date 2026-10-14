# API Laravel (PHP-FPM). A mesma imagem roda api, queue-worker e scheduler.
# Imagens base fixadas por digest (atualize junto com a revisão de segurança/licença).
FROM composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer

FROM php:8.4-fpm-alpine@sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a AS base
# gd (PNG/WebP/JPEG): recodificação segura de logos e logo no PDF; zip: XLSX e validação de modelos.
RUN apk add --no-cache icu-libs libzip libpq postgresql-client fcgi libpng libwebp libjpeg-turbo \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers libpng-dev libwebp-dev libjpeg-turbo-dev \
 && docker-php-ext-configure gd --with-webp --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip bcmath pcntl opcache gd \
 && apk del .build-deps
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-bpm.ini
COPY docker/www.conf /usr/local/etc/php-fpm.d/zz-bpm.conf
WORKDIR /var/www/html

FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

FROM base AS runtime
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
RUN rm -f .env .env.* \
 && composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && tr -d '\r' < docker/entrypoint.sh > /tmp/entrypoint.sh && mv /tmp/entrypoint.sh docker/entrypoint.sh \
 && chmod +x docker/entrypoint.sh
USER www-data
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php-fpm"]
