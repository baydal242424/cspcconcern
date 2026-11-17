# The application container: nginx and PHP-FPM, supervised together.
#
# Two processes in one container rather than two containers, because the
# hosting request this was written for asks for a container count and the
# answer given was two -- one for the application, one for the database.
# Splitting nginx out would make it three and buy nothing here: the web
# server and the interpreter serve one application, scale together, and
# have nothing else to talk to.
#
# No Node stage. The only @vite() call in this project is in
# welcome.blade.php, which no route reaches -- the application renders its
# own layout with inline CSS. Adding a build step would install a hundred
# megabytes of packages to produce a manifest nothing reads. If a real page
# ever uses Vite, add a `node:22-alpine` stage that runs `npm install &&
# npm run build` and copy `public/build` across.

# ---------------------------------------------------------------- vendor
# Composer runs in its own stage so the finished image carries neither
# Composer itself nor the dev dependencies.
FROM composer:2.8 AS vendor

WORKDIR /app

# The manifests alone first: this layer is rebuilt only when a dependency
# changes, not on every edit to the application.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# --------------------------------------------------------------- runtime
FROM php:8.4-fpm-alpine AS runtime

# nginx and supervisor to run them; the rest are build-time headers for the
# PHP extensions below, removed again in the same layer.
RUN apk add --no-cache \
        nginx \
        supervisor \
        libzip \
        icu-libs \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libzip-dev \
        icu-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        zip \
        intl \
        opcache \
    && apk del .build-deps

# Opcache, sized for an application that is deployed rather than edited in
# place. validate_timestamps=0 means a changed file is NOT picked up until
# the container restarts, which is what a deploy does anyway.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.interned_strings_buffer=16'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Uploads. The application caps attachments from config/concerns.php, which
# reads PHP's own limits -- so these two numbers are the ceiling the form
# will then advertise. Raise both together or the form promises what the
# server refuses.
RUN { \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=16M'; \
        echo 'memory_limit=256M'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# Stripped of carriage returns before it is made executable. The repository
# pins LF, but a Windows checkout with core.autocrlf=true hands the build a
# CRLF file, and a shell script with CRLF line endings fails inside the
# container as "no such file or directory" -- pointing at the interpreter,
# not at the ending, which is why it costs an afternoon to find.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint

# These two are written to at runtime; everything else stays read-only to
# the web user.
# Written out one path at a time. `{a,b,c}` is bash brace expansion and
# this image's shell is Alpine's /bin/sh, which does not do it: the braces
# are taken literally and one directory appears called
# "framework/{cache/data,sessions,views}". Nothing fails at build time --
# the application then dies on boot with "View path not found", which
# names the symptom and not the cause.
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/private \
        storage/app/public \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD wget --quiet --tries=1 --spider http://127.0.0.1/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
