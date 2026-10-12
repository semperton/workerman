# Build context is the repository root
FROM composer:2.10.1 AS composer

# FrankenPHP's own image, PHP is built thread safe (ZTS) to run inside Caddy
FROM dunglas/frankenphp:1.12.7-php8.4.26-trixie
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-enable opcache
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY benchmark/php.ini /usr/local/etc/php/conf.d/zz-bench.ini

WORKDIR /bench
COPY benchmark/app app
COPY benchmark/servers/frankenphp server
RUN cd server && composer install --no-dev --classmap-authoritative --no-interaction --no-progress
WORKDIR /bench/server
CMD ["frankenphp", "run", "--config", "/bench/server/Caddyfile"]
