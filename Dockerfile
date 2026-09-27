# Smart Emailing — production image.
#
# Two processes run from this one image: the web server, and a queue worker
# started with the same image but a different command (see Procfile). The
# worker is not optional — without it nothing is ever delivered.

FROM php:8.2-fpm-alpine

# System packages. PhpSpreadsheet needs gd, intl, zip and the XML extensions;
# a default PHP image ships none of them, and their absence only shows up at
# runtime when an export or import fails.
RUN apk add --no-cache \
        nginx \
        supervisor \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        zip \
        pdo_mysql \
        pdo_sqlite \
        bcmath \
        opcache \
    && rm -rf /var/cache/apk/*

# Composer, copied from its own image rather than curl-piped to a shell.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so a code-only change does not reinstall them.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

COPY . .

# Runs after the app code is present, so package discovery can see it.
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

# Laravel must be able to write these; everything else stays read-only.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Opcache settings that matter in production; the defaults are tuned for dev.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Uploads are capped at 10 MB by the form request; PHP must allow at least that.
RUN { \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=16M'; \
        echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 8080

ENTRYPOINT ["entrypoint"]
CMD ["web"]
